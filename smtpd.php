<?php

/*

##############################################
Guerrilla SMTPd
Copyright 2011

An SMTP server written in PHP, optimized for receiving email and storing in 
MySQL. Written for GuerrillaMail.com which processes thousands of emails
every hour.
Version: 1.1
Author: Clomode
Contact: flashmob@gmail.com
License: GPL (GNU General Public License, v3)
Repository: https://github.com/flashmob/Guerrilla-SMTPd
Site: http://www.guerrillamail.com/

See README for more details
###############################################

*/
// typically, this value should be set in php.ini, PHP may ignore it here!
ini_set('memory_limit', '512M');

// needed for trapping unix signals
declare (ticks = 1);

// It's a daemon! We should not exit... A warning though:
// PHP does have memory leaks and you may need to have another script to
// watch your daemon process and restart of needed.
set_time_limit(0);

// Register a shutdown function when we exit
register_shutdown_function('smtp_shutdown'); // shutdown sockets after a normal shutdown procedure

// You can costomize this
set_error_handler("error_handler");

// install a signal handler
pcntl_signal(SIGCHLD, "signal_handler");

// Process arguments
if (isset($argc) && ($argc > 1)) {
    foreach ($argv as $i => $arg) {
        if ($arg == '-p') {
            $listen_port = (int)$argv[$i + 1];
        }
        if ($arg == '-l') {
            $log_file = $argv[$i + 1];
        }
        if ($arg == '-v') {
            $verbose = true;
        }
    }
}
if (!isset($listen_port)) {
    $listen_port = 25;
}
if (isset($log_file)) {

    if (!file_exists($log_file) && file_exists(dirname(__file__) . '/' . $log_file)) {
        $log_file = dirname(__file__) . '/' . $log_file;
    } else {
        $log_file = dirname(__file__) . '/log.txt';
    }
} else {

    echo "log file not specified[]\n";
    $log_file = false;
}
if (!isset($verbose)) {

    $verbose = false;
}

##############################################################
# Configuration start
##############################################################

if (file_exists(dirname(__file__) . '/smtpd-config.php')) {
    // place a copy of the define statements in to smtpd-config.php
    require_once (dirname(__file__) . '/smtpd-config.php');
} else {
    // defaults if smtpd-config.php is not available
    define('MAX_SMTP_CLIENTS', 400);
    define('GSMTP_MAX_SIZE', 131072);
    define('GSMTP_HOST_NAME', 'guerrillamail.com');
    define('GSMTP_LOG_FILE', $log_file);
    define('GSMTP_VERBOSE', $verbose);
    define('GSMTP_TIMEOUT', 10); // how many seconds before timeout.
    define('MYSQL_HOST', 'localhost');
    define('MYSQL_USER', 'gmail_mail');
    define('MYSQL_PASS', 'ok');
    define('MYSQL_DB', 'gmail_mail');

    define('GM_MAIL_TABLE', 'new_mail'); // MySQL table for storage

    define('GM_PRIMARY_MAIL_HOST', 'guerrillamailblock.com'); // The primary domain name of you email.

    // Allowed hosts, a list of domains accepted by this server. Comma dilimited, do not include spaces
    define('GM_ALLOWED_HOSTS',
        'guerrillamailblock.com,guerrillamail.com,guerrillamail.net,guerrillamail.biz,guerrillamail.org,sharklasers.com');
    define('FORWARD_GMAIL_TO', 'flashmob@gmail.com');
    define('GMAIL_EMAIL', 'webmaster@sharklasers.com');

    define('GSMTP_VERIFY_USERS', false);

}
##############################################################
# Configuration end
##############################################################

/**
 * Returns a connection to MySQL
 * Returns the existing connection, if a connection was opened before.
 * On the consecutive call, It will ping MySQL if not called for 
 * the last 60 seconds to ensure that the connection is up.
 * Will attempt to reconnect once if the
 * connection is not up.
 * 
 * @param bool $reconnect True if you want the link to re-connect
 */
function &get_mysql_link($reconnect=false) {
    
    static $link;
    global $DB_ERROR;
    static $last_get_time;
    if (isset($last_get_time)) {
        // more than a minute ago?
        if (($last_get_time+60) < time()) {         
            if (false === mysql_ping($link)) {
                $reconnect = true; // try to reconnect
            }
        }
    }
    $last_get_time = time();
    
    if (isset($link) && !$reconnect) return $link;
    
    $DB_ERROR = '';
    $link = mysql_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASS) or $DB_ERROR =
    "Couldn't connect to server.";
mysql_select_db(MYSQL_DB, $link) or $DB_ERROR =
    "Couldn't select database.";
mysql_query("SET NAMES utf8");

    if ($DB_ERROR) {
        log_line($DB_ERROR, 1);
        return false;
    }
    
    return $link;
    
    
}


/**
 * error_handler()
 * 
 * @param int $errno
 * @param string $errstr
 * @param string $errfile
 * @param int $errline
 * @param array $errcontext
 * @return
 */
function error_handler($errno, $errstr, $errfile, $errline, $errcontext)
{


}


/**
 * signal_handler()
 * This function will be used in the future when the daemon supports forking
 * @param mixed $signal
 * @return
 */
function signal_handler($signal)
{
    global $master_socket;
    switch ($signal) {
        case SIGCHLD:
            while (pcntl_waitpid(0, $status) != -1) {
                $status = pcntl_wexitstatus($status);
                log_line("SIGCHILD caught: Child $status completed", 1);
            }
            break;
        case SIGTERM:
            global $clients;
            foreach ($clients as $k => $v) {
                if (is_rsource($v['soket'])) {
                    socket_shutdown($v['soket'], 2);
                    socket_close($v['soket']);
                }

            }
            log_line("SIGTERM caught: exiting", 1);
            socket_shutdown($master_socket, 2);
            socket_close($master_socket);
            exit;

    }
}
//
/**
 * smtp_shutdown()
 * This is our shutdown function, in
 * here we can do any last operations
 * before the script is complete.
 * Called by the system before the program exits.
 * Do not need to call this function directly.
 * @return
 */
function smtp_shutdown()
{

    global $clients;
    foreach ($clients as $client_id => $val) {
        if (is_resource($sock)) {
            close_client($clients[$client_id]['socket'], '');
        }
    }
}


/**
 * read_line()
 * Returns a data from the buffer only if the buffer is ready. Clears the
 * buffer before returning, and sets the 'read_buffer_ready' to false
 * @param array $clients
 * @param int $client_id
 * @return string, or false if no data was present in the buffer
 */
function read_line(&$clients, $client_id)
{


    if ($clients[$client_id]['read_buffer_ready']) {
        // clear the buffer and return the data
        $buf = $clients[$client_id]['read_buffer'];
        $clients[$client_id]['read_buffer'] = '';
        $clients[$client_id]['read_buffer_ready'] = false;
        return $buf;
    }
    return false;

}


/**
 * close_client()
 * Close a socket. Attempt to write a message before closing
 * @param resource $sock
 * @param string $msg
 * @return
 */
function close_client(&$sock, $msg = "221 Bye\r\n")
{
    if (strlen($msg) > 0) {
        if (substr($msg, -2) !== "\r\n") {
            $msg .= "\r\n";
        }
        socket_write($sock, $msg);
    }
    socket_shutdown($sock, 2);
    socket_close($sock);
}

/**
 * log_line()
 * Log a line of text. If -v argument was passed, level 1 messages
 * will be echoed to the console. Level 2 messages are always logged.
 * @param string $l
 * @param integer $log_level
 * @return
 */
function log_line($l, $log_level = 2)
{
    $l = trim($l);
    if (!strlen($l))
        return false;
    if (($log_level == 1) && (GSMTP_VERBOSE)) {
        echo $l . "\n";
    }
    if (GSMTP_LOG_FILE) {
        $fp = fopen(GSMTP_LOG_FILE, 'a');
        fwrite($fp, $l . "\n", strlen($l) + 1);
        fclose($fp);
    }
}

###############################################################
# Guerrilla SMTPd, Main

// Check MySQL connection

if (get_mysql_link()===false) {
    die('Please check your MySQL settings');
}
// Create a TCP Stream socket
/*
$master_sock = socket_create(AF_INET, SOCK_STREAM, 0);
// Bind the socket to an address/port
socket_bind($master_sock, $address, $listen_port) or die('Could not bind to address');
socket_listen($master_sock);
*/

$clients = array();
while (true) {
    // Loop until we acquire a socket
    $master_socket = socket_create_listen($listen_port);
    if ($master_socket === false) {
        log_line("Could not acquire the port, will try again: " . socket_strerror(socket_last_error
            ()), 1);
        sleep(5);
    } else {
        break;
    }
}
$next_id = 1;
log_line("Guerrilla Mail Daemon started on port " . $listen_port, 1);

// This is in a for loop so that future versions of this deamon can be forked.

for (;; ) {
    $newpid = false;
    //$newpid = pcntl_fork(); // TO DO
    if ($newpid === -1) {
        die("Couldn't fork()!");
    } else
        if (!$newpid) {
            // the child
            //posix_setsid();
            /* TO DO Accept incoming requests and handle them as child processes */

            $client_count = 0;
            while (is_resource($master_socket)) {

                // is_resource $master_socket
                if ($client_count < MAX_SMTP_CLIENTS) {
                    $read[0] = $master_socket; // socket to poll for accepting new connections
                }
                ###################################
                # READ from the sockets or accept new connections
                $N = null;
                if (!empty($read)) {

                    $ready = socket_select($read, $N, $N, null); // are there any sockets need reading?
                    if ($ready) {
                        if (in_array($master_socket, $read)) { // new connection?
                            $new_client = socket_accept($master_socket);
                            if ($new_client !== false) {
                                $client_count++;

                                $clients[$next_id]['socket'] = $new_client; // new socket
                                $clients[$next_id]['state'] = 0;
                                $clients[$next_id]['mail_from'] = '';
                                $clients[$next_id]['rcpt_to'] = '';
                                $clients[$next_id]['error_c'] = 0;
                                $clients[$next_id]['read_buffer'] = '';
                                $clients[$next_id]['read_buffer_ready'] = false; // true if the buffer is ready to be fetched
                                $clients[$next_id]['write_buffer'] = '';
                                $clients[$next_id]['response'] = ''; // response messages are placed here, before they go on the write buffer
                                $clients[$next_id]['time'] = time();
                                $address = '';
                                $port = '';
                                socket_getpeername($clients[$next_id]['socket'], $address, $port);
                                $clients[$next_id]['address'] = $address;

                                $next_id++;
                                log_line('Accepted a new client[' . $next_id . '] (' . $address . ':' . $port .
                                    ')' . " There are $client_count clients(" . sizeof($clients) . ")", 1);
                            }

                        }

                        unset($read[0]); // remove the master socket, we do not read it


                        # Check each soocket and read from it
                        foreach ($read as $client_id => $sock) {
                            if ($listen_port == 2525) {
                                // For debugging, only when running under port 2525
                                echo "[$client_id]omn nom nom (" . strlen($clients[$client_id]['read_buffer']) .
                                    ")\r\n";
                            }

                            $buff = socket_read($sock, 1024);
                            while (true) {
                                if ($buff === '') {
                                    // no more to read

                                    if (($clients[$client_id]['time'] + GSMTP_TIMEOUT) < time()) {
                                        log_line("[$client_id] Timed Out! state:" . $clients[$client_id]['state'], 1);
                                        // nothing read for over 10 sec, TIMEOUT!
                                        kill_client($client_id, $clients, $read, '421 ' . GSMTP_HOST_NAME .
                                            ': SMTP command timeout - closing connection');
                                    }
                                    break;
                                } elseif ($buff === false) {
                                    // error
                                    log_line('[' . $client_id . ']failed to read from:' . socket_strerror(socket_last_error
                                        ($sock)));
                                    kill_client($client_id, $clients, $read);

                                    break;
                                } else {
                                    // Read the data in to the read buffer

                                    $clients[$client_id]['time'] = time();
                                    $clients[$client_id]['read_buffer'] .= $buff;

                                    // Determine if the buffer is ready
                                    // The are two states when we determine if the buffer is ready.
                                    // State 1 is the command state, when we wait for a command from
                                    // the client
                                    // State 2 is the DATA state, when the client gives is the data
                                    // for the email.

                                    if ($clients[$client_id]['state'] === 1) {
                                        // command state, strings terminate with \r\n
                                        if (strpos($buff, "\r\n", strlen($buff) - 2) !== false) {
                                            $clients[$client_id]['read_buffer_ready'] = true;
                                        }

                                    } elseif ($clients[$client_id]['state'] === 2) {
                                        // DATA reading state
                                        // not ready unless you get a \r\n.\r\n at the end
                                        $len = strlen($clients[$client_id]['read_buffer']);
                                        if (($len > GSMTP_MAX_SIZE) || (($len > 4) && (strpos($clients[$client_id]['read_buffer'],
                                            "\r\n.\r\n", $len - 5)) !== false)) {
                                            $clients[$client_id]['read_buffer_ready'] = true; // finished
                                            $clients[$client_id]['read_buffer'] = substr($clients[$client_id]['read_buffer'],
                                                0, $len - 5);
                                        }
                                    }

                                    break;

                                }
                            }
                        }
                    } else {
                        // socket select failed for some reason
                        log_line("socket_select() failed, reason: " . socket_strerror(socket_last_error
                            ()), 1);

                    }
                }

                // process timeouts for sockets we didn't read
                foreach ($clients as $client_id => $client) {
                    if (!in_array($clients[$client_id]['socket'], $read)) {
                        // we didn't read any data from this socket
                        if (($clients[$client_id]['time'] + GSMTP_TIMEOUT) < time()) {
                            log_line("[$client_id] Timed Out! state:" . $clients[$client_id]['state'], 1);
                            // nothing read for over 10 sec, TIMEOUT!
                            kill_client($client_id, $clients, $read, '421 ' . GSMTP_HOST_NAME .
                                ': SMTP command timeout - closing connection');

                        }
                    }
                }


                ###################################
                # Process the protocol state

                $read = array();

                foreach ($clients as $client_id => $client) {

                    if (is_resource($clients[$client_id]['socket'])) {
                        // place the socket on the reading list
                        $read[$client_id] = $clients[$client_id]['socket']; // we want to read this socket
                    } else {
                        kill_client($client_id, $clients, $read, '');
                        continue; // skip this loop, go to the next client
                    }

                    $input = '';
                    switch ($clients[$client_id]['state']) {
                        case 0:
                            $clients[$client_id]['response'] = '220 ' . $host_name . ' SMTP Service at ' .
                                date('r');
                            $clients[$client_id]['state'] = 1;

                            break;
                        case 1:

                            $input = read_line($clients, $client_id);
                            if ($input)
                                log_line('[' . $client_id . '] cmd:' . $input);
                            if ($input) {

                                if (strpos($input, 'HELO') !== false) {
                                    $temp = explode(' ', $input);
                                    $clients[$client_id]['response'] = '250 Hello ' . trim($temp[1]) .
                                        ', I am glad to meet you';
                                } elseif (strpos($input, 'EHLO') !== false) {
                                    $temp = explode(' ', $input);
                                    $address = '';
                                    $port = '';
                                    socket_getpeername($clients[$client_id]['socket'], $address, $port);
                                    $clients[$client_id]['response'] = '250-' . GSMTP_HOST_NAME . ' Hello ' . trim($temp[1]) .
                                        '[' . $address . ']' . "\r\n" . "250-SIZE ".GSMTP_MAX_SIZE."\r\n" . //"250-PIPELINING\r\n" .
                                        //"250-AUTH PLAIN LOGIN\r\n" .
                                    //"250-STARTTLS\r\n" .
                                    "250 HELP";
                                } elseif (strpos($input, 'MAIL FROM:') !== false) {
                                    $clients[$client_id]['response'] = '250 Ok';
                                } elseif (strpos($input, 'RCPT TO:') !== false) {
                                    if (empty($clients[$client_id]['rcpt_to'])) {
                                        $clients[$client_id]['rcpt_to'] = $input;
                                        $clients[$client_id]['response'] = '250 Accepted';
                                    } else {
                                        // do not let CC. 
                                        kill_client($client_id, $clients, $read,
                                            '550 Requested action not taken: mailbox unavailable');
                                    }

                                    $clients[$client_id]['response'] = '250 Accepted';
                                } elseif (strpos($input, 'DATA') !== false) {
                                    $clients[$client_id]['response'] =
                                        '354 Enter message, ending with "." on a line by itself';
                                    $clients[$client_id]['state'] = 2;
                                    $clients[$client_id]['data_len'] = 0;
                                    $clients[$next_id]['read_buffer'] = '';
                                } elseif (strpos($input, 'QUIT') !== false) {

                                    log_line("client asked to quit", 1);
                                    kill_client($client_id, $clients, $read, '221 Bye');
                                    continue;

                                } elseif (strpos($input, 'NOOP') !== false) {

                                    log_line("client NOOP from client", 1);
                                    unset($read[$client_id]);
                                } else {
                                    log_line('[' . $client_id . ']unrecoginized cmd:' . $input, 1);
                                    $clients[$client_id]['response'] = '500 unrecognized command';
                                    $clients[$client_id]['error_c']++;
                                    if (($clients[$client_id]['error_c'] > 3)) {
                                        kill_client($client_id, $clients, $read, '500 Too many unrecognized commands');
                                        continue;

                                    }
                                }
                            }
                            break;
                        case 2:

                            $input = read_line($clients, $client_id);

                            if ($input) {

                                list($id, $to) = save_email($input);
                                if ($id) {
                                    $clients[$client_id]['response'] = '250 OK : queued as ' . $id;
                                } else {
                                    // The email didn't save properly, usualy because it was in
                                    // an incorrect mime format or bad recipient
                                    $clients[$client_id]['response'] = "554 Transaction failed";
                                }

                                kill_client($client_id, $clients, $read, $clients[$client_id]['response']);


                                log_line("Message for client [$client_id] processed to [$to], told client to exit.",
                                    1);
                                continue;


                            }
                            break;


                    }
                }

                ###################################
                # Write a response

                $write = array(); // sockets we want to write to
                foreach ($clients as $client_id => $client) {
                    // buld a list of sockets that need writing

                    if (!is_resource($client['socket'])) {
                        kill_client($client_id, $clints, $read, '');
                        continue;
                    } elseif (strlen($clients[$client_id]['response']) > 0) {

                        if (substr($clients[$client_id]['response'], -2) !== "\r\n") {
                            $clients[$client_id]['response'] .= "\r\n";
                        }
                        // append the response to the end of the buffer
                        $clients[$client_id]['write_buffer'] .= $clients[$client_id]['response'];
                        $clients[$client_id]['response'] = '';

                    }
                    if ($clients[$client_id]['write_buffer']) {
                        // place this socket on the check-list for socket_select()
                        $write[$client_id] = $client['socket'];
                    }
                }
                if (!empty($write)) {

                    $ready = socket_select($N, $write, $N, null); // are there any sockets need writing?
                    if ($ready) {
                        foreach ($write as $client_id => $sock) {
                            /*
                            If you read/write to a socket, be aware that they do not necessarily read/write 
                            the full amount of data you have requested. 
                            Be prepared to even only be able to read/write a single byte.
                            */
                            $len = socket_write($sock, $clients[$client_id]['write_buffer'], 
                                        strlen($clients[$client_id]['write_buffer'])); // we have bufffered a response?

                            if ($len) {
                                $address = '';
                                $port = '';
                                socket_getpeername($sock, $address, $port);
                                log_line('[' . $client_id . ']' . $address . ':' . $port . '=>' . $clients[$client_id]['write_buffer']);
                                // remove form the buffer the number of characters written out
                                $clients[$client_id]['write_buffer'] = substr($clients[$client_id]['write_buffer'],
                                    $len);


                            } elseif ($len === false) {
                                log_line('[' . $client_id . ']Failed to write to ' . $clients[$client_id]['address'] .
                                    ':' . socket_strerror(socket_last_error()), 1);
                                kill_client($client_id, $clients, $read, '');
                            }
                        }
                    }
                }


            } // end while
            // Close the client (child) socket

            if (is_resource($master_socket)) {
                socket_shutdown($master_socket, 2);
                socket_close($master_socket);
            }
            exit();
        }
}


/**
 * kill_client()
 * Close the socket and remove the client from the list. Attempt to
 * send a message before closing the socket (if the socket is a resource)
 * @param int $client_id
 * @param array $clients
 * @param array $read
 * @param string $msg
 * @return
 */
function kill_client($client_id, &$clients, &$read, $msg = null)
{
    global $client_count;
    if (isset($clients[$client_id])) {

        if (is_resource($clients[$client_id]['socket'])) {
            close_client($clients[$client_id]['socket'], $msg);
            $client_count--;
            log_line("client killed [" . $clients[$client_id]['address'] . "]", 1);
        }
        unset($clients[$client_id]);

        unset($read[$client_id]);

    }


}
#########################################################################################
# Mail Parsing and storage to MySQL

/**
 * mail_body_decode()
 * Decode the mail body to binary. Then convert to UTF-8 if not already
 * @param string $str string to decode
 * @param string $encoding_type eg. 'quoted-printable' or 'base64'
 * @param string $charset and of the charsets supported by iconv()
 * @return string decoded message in a string of UTF-8
 */
function mail_body_decode($str, $encoding_type, $charset = 'UTF-8')
{

    if ($encoding_type == 'base64') {
        $str = base64_decode($str);
    } elseif ($encoding_type == 'quoted-printable') {
        $str = quoted_printable_decode($str);
    }

    if (strtoupper($charset) != 'UTF-8') {
        $str = @iconv(strtoupper($charset), 'UTF-8', $str);
    }
    return $str;


}

/**
 * extract_email()
 * Extract an email address from a header string
 * @param string $str
 * @return string email address, false if none found
 */
function extract_email($str)
{
    static $allowed_hosts;
    if (!$allowed_hosts) {
        $allowed_hosts = explode(',', GM_ALLOWED_HOSTS);
    }

    $arr = imap_rfc822_parse_adrlist($str, GM_PRIMARY_MAIL_HOST);

    foreach ($arr as $item) {

        if (in_array(strtolower($item->host), $allowed_hosts)) {
            return strtolower($item->mailbox . '@' . $item->host);
        }
    }
    return false;

}

/**
 * extract_from_email()
 * See extract_email
 * @param string $str
 * @return string
 */
function extract_from_email($str)
{

    $arr = imap_rfc822_parse_adrlist($str, GM_PRIMARY_MAIL_HOST);
    foreach ($arr as $item) {
        return strtolower($item->mailbox . '@' . $item->host);
    }
    return false;

}


/**
 * save_email()
 * Accepts an email received from a client during the DATA command.
 * This email is processed, the recipient host is verified, the body is
 * decoded, then saved to the database.
 * 
 * @param string $email
 * @return array, with the following elements array($hash, $recipient)
 * where the $hash is a unique id for this email.
 */
function save_email($email)
{
    

    global $listen_port;
    $mimemail = null;
    $spam_score = '';

    $mimemail = mailparse_msg_create(); // be sure to free this for each email to avoid memory leaks
    if ($listen_port == 2525) {
        // we use port 2525 for testing, start with -p 2525 on the command line
        echo $email;
    }

    mailparse_msg_parse($mimemail, $email);
    $struct = mailparse_msg_get_structure($mimemail);
    $parts = array();
    $body = '';

    // Find the body of the email, decode it and change to UTF-8
    // If a message has a html and text part, use the html part
    foreach ($struct as $part_id) {

        $part = mailparse_msg_get_part($mimemail, $part_id);
        $parts[$part_id] = mailparse_msg_get_part_data($part);


        $start = $parts[$part_id]['starting-pos-body'];
        $end = $parts[$part_id]['ending-pos-body'];
        if (isset($parts[$part_id]['content-charset'])) {
            $charset = $parts[$part_id]['content-charset'];
        } else {
            if (empty($charset)) {
                $charset = 'ISO-8859-1';
            }
        }
        if (isset($parts[$part_id]['transfer-encoding'])) {
            $transfer_encoding = $parts[$part_id]['transfer-encoding'];
        } else {
            if (empty($transfer_encoding)) {
                $transfer_encoding = '7bit';
            }
        }
        if (isset($parts[$part_id]['content-type'])) {
            $content_type = $parts[$part_id]['content-type'];
        } elseif (empty($content_type)) {
            $content_type = 'text/plain';
        }


        if ($parts[$part_id]['content-type'] == 'text/html') {
            $body = substr($email, $start, $end - $start);
            $body = mail_body_decode($body, $transfer_encoding, $charset);
            $content_type = $parts[$part_id]['content-type'];
            if (trim($body)) {
                break; // exit the foreach - use this one
            }
        } elseif ($parts[$part_id]['content-type'] == 'text/plain') {
            $body = substr($email, $start, $end - $start);
            $body = mail_body_decode($body, $transfer_encoding, $charset);
            $content_type = $parts[$part_id]['content-type'];
            // do not exit, continue loop - maybe there is a html part?
        }
        if (!$body) {
            // last resort, only if body is blank
            // Sometimes the message may not be using MIME
            // We can chop of the header and simply include the rest as the body.
            $body = substr($email, strpos($email, "\r\n\r\n"), strlen($email));
            $body = mail_body_decode($body, $transfer_encoding, $charset);
            $content_type = $content_type;
        }
    }

    $to = extract_email($parts[1]['headers']['to']);
    $recipient = $to;
    $from = extract_from_email($parts[1]['headers']['from']);
    $subject = ($parts[1]['headers']['subject']);
    $date = $parts[1]['headers']['date']; //
    //eg, subject can be: =?ISO-8859-1?Q?T=E9l=E9chargez_une_photo_de_profil_!?=
    if ($listen_port == 2525) {
        // we use port 2525 for testing, start with -p 2525 on the command line
        echo "bo\;l\;lkjfdsay:[" . $body . ']';
    }
    if (is_array($subject)) {
        error_log(var_export($subject, true));
        $subject = array_pop($subject);
    }
    $subject = @iconv_mime_decode($subject, 1, 'UTF-8');


    list($mail_user, $mail_host) = explode('@', $to);
    $GM_ALLOWED_HOSTS = explode(',', GM_ALLOWED_HOSTS);
    
    /*
    What is $spam_score? Earlier versions used spamd (Spam Assasin)
    to check the spam score of an email. Email the author if you are
    interested in this.
    */

    if (in_array($mail_host, $GM_ALLOWED_HOSTS) && ($spam_score < 5.1)) {
        
        $mysql_link = get_mysql_link();
        if ($mysql_link===false) {
            // could not get a db connection
            return array(false, false);
        }

        $to = $mail_user . '@' . GM_PRIMARY_MAIL_HOST; // change it to the primary host

        if (GSMTP_VERIFY_USERS) {
            // Here we can verify that the recipient is actually in the database.
            // Note that guerrillamail.com does not do this - you can send email
            // to a non-existing email address, and set to this email later.
            // just an example:
            if (array_pop(explode('@', $recipient)) !== 'sharklasers.com') {
                // check the user againts our user database
                $user = array_shift(explode('@', $recipient));
                $sql = "SELECT * FROM `gm2_address` WHERE `address_email`='" .
                    mysql_real_escape_string($user) . "@guerrillamailblock.com' ";
                $result = mysql_query($sql);
                if (mysql_num_rows($result) == 0) {
                    return; // no such address
                }
            }
        }

        $hash = md5($to . $from . $subject . $body); // generate an id for the email

        mysql_query("Lock tables " . GM_MAIL_TABLE . " write, gm2_setting write");

        $sql = "INSERT INTO " . GM_MAIL_TABLE .
            " (`date`, `to`, `from`, `subject`, `body`, `charset`, `mail`, `spam_score`, `hash`, `content_type`, `recipient` ) VALUES ('" .
            gmdate('Y-m-d H:i:s') . "', '" . mysql_real_escape_string($to) . "', '" .
            mysql_real_escape_string($from) . "', '" . mysql_real_escape_string($subject) .
            "',  '" . mysql_real_escape_string($body) . "', '" . mysql_real_escape_string($charset) .
            "', '" . mysql_real_escape_string($email) . "', '" . mysql_real_escape_string($spam_score) .
            "', '" . mysql_real_escape_string($hash) . "', '" . mysql_real_escape_string($content_type) .
            "', '" . mysql_real_escape_string($recipient) . "') ";

        mysql_query($sql) or log_line(mysql_error());
        $id = mysql_insert_id();
        if ($id) {
            $sql = "UPDATE gm2_setting SET `setting_value` = `setting_value`+1 WHERE `setting_name`='received_emails' LIMIT 1";
            mysql_query($sql);
        } else {
            log_line('Failed to save email From:'.$from.' To:'.$recipient, 1);
        }
        mysql_query("UNLOCK TABLES");

    }
    log_line('save_email() called, to:[' . $recipient . '] ID:' . $id);

    mailparse_msg_free($mimemail); // very important or else the server will leak memory
    return array($hash, $recipient);
}


?>