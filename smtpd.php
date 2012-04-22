<?php
/**


Guerrilla SMTPd
An minimalist, event-driven I/O, non-blocking SMTP server in PHP

Copyright (c) 2012 Flashmob, GuerrillaMail.com

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

What is Guerrilla SMTPd?
It's a small SMTP server written in PHP, optimized for receiving email.
Written for GuerrillaMail.com which processes tens of thousands of emails
every hour.

Version: 2.0
Author: Flashmob, GuerrillaMail.com
Contact: flashmob@gmail.com
License: MIT
Repository: https://github.com/flashmob/Guerrilla-SMTPd
Site: http://www.guerrillamail.com/

See README for more details

Version History:

2.0
- First release, re-write of Guerrilla SMTPd 1.2 to use libevent
http://www.php.net/manual/en/book.libevent.php

 */


$fp = fopen(dirname(__FILE__) . "/tmp/smtpd2_lock.txt", "w");

if (flock($fp, LOCK_EX | LOCK_NB)) { // do an exclusive lock, non-blocking
    ftruncate($fp, 0);
    fwrite($fp, "Write something here\n");

} else {
    echo "Couldn't get the lock!";
    fclose($fp);
    die();
}

// It's a daemon! We should not exit... A warning though:
// You may need to have another script to
// watch your daemon process and restart of needed.
set_time_limit(0);


/**
 * Arguments
 * -p <port>        listen on port
 * -l <log_file>    log to log_file
 * -v               output to console
 */
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
    // defaults if smtpd-config2.php is not available
    log_line('Loading defaults', 1);

    define('MAX_SMTP_CLIENTS', 10);
    define('GSMTP_MAX_SIZE', 131072);
    define('GSMTP_HOST_NAME', 'guerrillamail.com'); // This should also be set to reflect your RDNS
    define('GSMTP_LOG_FILE', $log_file);
    define('GSMTP_VERBOSE', $verbose);
    define('GSMTP_TIMEOUT', 100); // how many seconds before timeout.
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
function &get_mysql_link($reconnect = false)
{

    static $link;
    global $DB_ERROR;
    static $last_get_time;
    if (isset($last_get_time)) {
        // more than a minute ago?
        if (($last_get_time + 60) < time()) {
            if (false === mysql_ping($link)) {
                $reconnect = true; // try to reconnect
            }
            $last_get_time = time();
        }
    } else {
        $last_get_time = time();
    }


    if (isset($link) && !$reconnect)
        return $link;

    $DB_ERROR = '';
    $link = mysql_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASS) or $DB_ERROR =
        "Couldn't connect to server.";
    mysql_select_db(MYSQL_DB, $link) or $DB_ERROR = "Couldn't select database.";
    mysql_query("SET NAMES utf8");

    if ($DB_ERROR) {
        log_line($DB_ERROR, 1);
        return false;
    }

    return $link;

}

function &get_memcache()
{
    static $memcache;
    if (is_object($memcache)) {
        return $memcache;
    }
    $memcache = new Memcache;
    $memcache->connect('localhost', 11211) or die ("Could not connect");
    return $memcache;

}

##############################################################
# Guerrilla SMTPd, Main
##############################################################

$GM_ALLOWED_HOSTS = explode(',', GM_ALLOWED_HOSTS);

// Check MySQL connection

if (get_mysql_link() === false) {
    die('Please check your MySQL settings');
}

$next_id = 1;

/**
 * $clients array List of all clients currently connected including session data for each client
 */
$clients = array();

/**
 * Setup the main event loop, open a non-blocking stream socket and set the
 * ev_accept() function to accept new connection events
 */
$socket = stream_socket_server('tcp://0.0.0.0:' . $listen_port, $errno, $errstr);
stream_set_blocking($socket, 0);
$base = event_base_new();
$event = event_new();
event_set($event, $socket, EV_READ | EV_PERSIST, 'ev_accept', $base);
event_base_set($event, $base);
event_add($event);
log_line("Guerrilla Mail Daemon started on port " . $listen_port, 1);

event_base_loop($base);


/**
 * Handle new connection events. Add new clients to the list. The server will write a welcome message to each client
 * Sets the following functions to handle I/O events
 * 'ev_read()', 'ev_write()', 'ev_error()'
 * @param $socket resource
 * @param $flag int A flag indicating the event. Consists of the following flags: EV_TIMEOUT, EV_SIGNAL, EV_READ, EV_WRITE and EV_PERSIST.
 * @param $base resource created by event_base_new()
 */
function ev_accept($socket, $flag, $base)
{

    global $clients;
    static $next_id = 0;

    $connection = stream_socket_accept($socket);
    stream_set_blocking($connection, 0);

    $next_id++;

    $buffer = event_buffer_new($connection, 'ev_read', 'ev_write', 'ev_error', $next_id);
    event_buffer_base_set($buffer, $base);
    event_buffer_timeout_set($buffer, GSMTP_TIMEOUT, GSMTP_TIMEOUT);
    event_buffer_watermark_set($buffer, EV_READ, 0, 0xffffff);
    event_buffer_priority_set($buffer, 10);
    event_buffer_enable($buffer, EV_READ | EV_PERSIST);

    $clients[$next_id]['socket'] = $connection; // new socket
    $clients[$next_id]['ev_buffer'] = $buffer; // new socket
    $clients[$next_id]['state'] = 0;
    $clients[$next_id]['mail_from'] = '';
    $clients[$next_id]['helo'] = '';
    $clients[$next_id]['rcpt_to'] = '';
    $clients[$next_id]['error_c'] = 0;
    $clients[$next_id]['read_buffer'] = '';
    $clients[$next_id]['read_buffer_ready'] = false; // true if the buffer is ready to be fetched

    $clients[$next_id]['response'] = ''; // response messages are placed here, before they go on the write buffer
    $clients[$next_id]['time'] = time();

    $address = stream_socket_get_name($clients[$next_id]['socket'], true);
    $clients[$next_id]['address'] = $address;

    process_smtp($next_id);

    if (strlen($clients[$next_id]['response']) > 0) {
        event_buffer_write($buffer, $clients[$next_id]['response']);
        add_response($next_id, null);
    }
}

/**
 * Handle error events, including timeouts
 * @param $buffer resource Event buffer
 * @param $error int flag (EV_TIMEOUT, EV_SIGNAL, EV_READ, EV_WRITE and EV_PERSIST)
 * @param $id int client id
 */
function ev_error($buffer, $error, $id)
{
    global $clients;
    log_line("event error $error", 1);
    event_buffer_disable($clients[$id]['ev_buffer'], EV_READ | EV_WRITE);
    event_buffer_free($clients[$id]['ev_buffer']);
    fclose($clients[$id]['socket']);
    unset($clients[$id]);
}

function ev_write($buffer, $id)
{
    global $clients;
    // close if the client is on the kill list

    if (!empty($clients[$id]['kill_time'])) {
        event_buffer_disable($clients[$id]['ev_buffer'], EV_READ | EV_WRITE);
        event_buffer_free($clients[$id]['ev_buffer']);
        fclose($clients[$id]['socket']);
        unset($clients[$id]);
    }

}

function ev_read($buffer, $id)
{
    global $clients;
    while ($read = event_buffer_read($buffer, 1024)) {
        $clients[$id]['read_buffer'] .= $read;
    }


    // Determine if the buffer is ready
    // The are two states when we determine if the buffer is ready.
    // State 1 is the command state, when we wait for a command from
    // the client
    // State 2 is the DATA state, when the client sends the data
    // for the email.

    if ($clients[$id]['state'] === 1) {
        // command state, strings terminate with \r\n
        if (strpos($clients[$id]['read_buffer'], "\r\n", strlen($clients[$id]['read_buffer']) - 2) !== false) {
            $clients[$id]['read_buffer_ready'] = true;
        }

    } elseif ($clients[$id]['state'] === 2) {
        // DATA reading state
        // not ready unless you get a \r\n.\r\n at the end
        $len = strlen($clients[$id]['read_buffer']);
        if (($len > GSMTP_MAX_SIZE) || (($len > 4) && (strpos($clients[$id]['read_buffer'],
            "\r\n.\r\n", $len - 5)) !== false)
        ) {
            $clients[$id]['read_buffer_ready'] = true; // finished
            $clients[$id]['read_buffer'] = substr($clients[$id]['read_buffer'],
                0, $len - 5);
        }
    }

    process_smtp($id);

    if (strlen($clients[$id]['response']) > 0) {

        event_buffer_write($buffer, $clients[$id]['response']);
        add_response($id, null);
    }


}


///////////////////////////////////////////////////

/**
 * SMTP server state machine. Use read_line() to get input from the buffer, add_response() to queue things
 * to the output buffer, kill_client() to stop talking to the client. save_email() to store the email.
 *
 * @param $client_id int
 */
function process_smtp($client_id)
{

    global $clients;


    switch ($clients[$client_id]['state']) {
        case 0:

            add_response($client_id, '220 ' . GSMTP_HOST_NAME .
                ' SMTP Guerrilla-SMTPd #' . $client_id . ' (' . sizeof($clients) . ') ' . gmdate('r'));
            $clients[$client_id]['state'] = 1;

            break;
        case 1:

            $input = read_line($clients, $client_id);
            if ($input)
                log_line('[' . $client_id . '] cmd:' . $input);
            if ($input) {

                if (stripos($input, 'HELO') !== false) {
                    $temp = explode(' ', $input);
                    $clients[$client_id]['helo'] = trim($temp[1]);
                    add_response($client_id, '250 ' . GSMTP_HOST_NAME . ' Hello ' . trim($temp[1]) .
                        ' [' . $clients[$client_id]['address'] . '], got some spam for me?');
                } elseif (stripos($input, 'EHLO') !== false) {
                    $temp = explode(' ', $input);

                    $clients[$client_id]['helo'] = trim($temp[1]);
                    add_response($client_id, '250-' . GSMTP_HOST_NAME . ' Hello ' . trim($temp[1]) .
                        '[' . $clients[$client_id]['address'] . ']' . "\r\n" . "250-SIZE " .
                        GSMTP_MAX_SIZE . "\r\n" . //"250-PIPELINING\r\n" .
                        //"250-AUTH PLAIN LOGIN\r\n" .
                        //"250-STARTTLS\r\n" .
                        "250 HELP");
                } elseif (stripos($input, 'MAIL FROM:') === 0) {
                    $clients[$client_id]['mail_from'] = substr($input, 10);
                    add_response($client_id, '250 Ok');
                } elseif ((stripos($input, 'RCPT TO:') !== false)) {

                    $email = extract_email(substr($input, 8));
                    // do not allow CC, RCPT TO is allowed only once
                    if (empty($clients[$client_id]['rcpt_to']) && ($email)) {
                        $clients[$client_id]['rcpt_to'] = $email;
                        add_response($client_id, '250 Accepted');
                    } else {
                        log_line('mailbox unavailable[' . array_pop(explode('@', $input)) . '] input:' .
                            $input, 1);
                        // do not let CC.
                        kill_client($client_id,
                            '550 Requested action not taken: mailbox unavailable');
                    }

                } elseif (stripos($input, 'DATA') !== false) {
                    add_response($client_id,
                        '354 Enter message, ending with "." on a line by itself');
                    $clients[$client_id]['state'] = 2;

                    $clients[$client_id]['read_buffer'] = '';
                } elseif (stripos($input, 'QUIT') !== false) {

                    log_line("client asked to quit", 1);
                    kill_client($client_id, '221 Bye');
                    continue;

                } elseif (stripos($input, 'NOOP') !== false) {

                    log_line("client NOOP from client", 1);

                    add_response($client_id, '250 OK');

                } elseif (stripos($input, 'RSET') !== false) {

                    $clients[$client_id]['read_buffer'] = '';
                    $clients[$client_id]['rcpt_to'] = '';
                    $clients[$client_id]['mail_from'] = '';
                    add_response($client_id, '250 OK');

                } else
                {
                    log_line('[' . $client_id . ']unrecoginized cmd:' . $input, 1);
                    add_response($client_id, '500 unrecognized command');
                    $clients[$client_id]['error_c']++;
                    if (($clients[$client_id]['error_c'] > 3)) {
                        kill_client($client_id, '500 Too many unrecognized commands');
                        continue;

                    }
                }
            }
            break;
        case 2:

            $input = read_line($clients, $client_id);

            if ($input) {

                list($id, $to) = save_email($input, $clients[$client_id]['rcpt_to'], $clients[$client_id]['helo'],
                    $clients[$client_id]['address']);

                if ($id) {
                    add_response($client_id, '250 OK : queued as ' . $id);
                    // put client back to state 1
                    $clients[$client_id]['state'] = 1;
                    $clients[$client_id]['read_buffer'] = '';
                    $clients[$client_id]['error_c'] = 0;
                } else {
                    // The email didn't save properly, usualy because it was in
                    // an incorrect mime format or bad recipient

                    kill_client($client_id, "554 Transaction failed");
                    log_line("Message for client: [$client_id] failed to [$to] {" . $clients[$client_id]['rcpt_to'] . "}, told client to exit.",
                        1);
                }
                continue;
            }
            break;
    }
}


/**
 *
 * Log a line of text. If -v argument was passed, level 1 messages
 * will be echoed to the console. Level 2 messages are always logged.
 * @param string $l
 * @param integer $log_level
 *
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

/**
 * Queue a response back to the client. This will be sent as soon as we get an event
 * @param $client_id
 * @param null|string $str response to send. \r\n will be added automatically. Use null to clear
 */
function add_response($client_id, $str = null)
{
    global $clients;
    if (strlen($str) > 0) {
        if (substr($str, -2) !== "\r\n") {
            $str .= "\r\n";
        }
        $clients[$client_id]['response'] .= $str;
    } elseif ($str === null) {
        // clear
        $clients[$client_id]['response'] = null;
    }

}

/**
 * @param $client_id
 * @param $clients
 * @param null|string $msg message to the client. Do not kill untill all is sent
 */
function kill_client($client_id, $msg = null)
{
    global $clients;
    if (isset($clients[$client_id])) {

        $clients[$client_id]['kill_time'] = time();
        if (strlen($msg) > 0) {
            add_response($client_id, $msg);
        }
    }
}

/**
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

#########################################################################################
# Mail Parsing and storage to MySQL
/**
 * Use php's ability to set an error handler, since iconv may sometimes give
 * warnings. this allows us to trap these warnings
 */
function iconv_error_handler($errno, $errstr, $errfile, $errline)
{
    global $iconv_error;
    $iconv_error = true;

}

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
    global $iconv_error;
    $iconv_error = false;

    if ($encoding_type == 'base64') {
        $str = base64_decode($str);
    } elseif ($encoding_type == 'quoted-printable') {
        $str = quoted_printable_decode($str);
    }

    if (strtoupper($charset) != 'UTF-8') {
        $old_error_handler = set_error_handler("iconv_error_handler");
        $str = @iconv(strtoupper($charset), 'UTF-8', $str);
        if ($iconv_error) {
            // there was iconv error
            // attempt mbstring concersion
            $str = mb_convert_encoding($str, 'UTF-8', $charset);
            return $str;
        }
        restore_error_handler();
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
function save_email($email, $rcpt_to, $helo, $helo_ip)
{


    global $listen_port;
    $mimemail = null;
    $hash = '';
    $email .= "\r\n";

    list($to, $from, $subject) = get_email_headers($email, array('To', 'From', 'Subject'));
    $to = extract_email($to);
    $from = extract_from_email($from);



    if (is_array($subject)) {

        $subject = array_pop($subject);
    }
    $subject = @iconv_mime_decode($subject, 1, 'UTF-8');


    list($mail_user, $mail_host) = explode('@', $to);

    global $GM_ALLOWED_HOSTS; // allowed hosts


    $id = ''; // generated message id
    if (in_array($mail_host, $GM_ALLOWED_HOSTS)) {

        $mysql_link = get_mysql_link();
        if ($mysql_link === false) {
            // could not get a db connection
            return array(false, false);
        }

        $to = $mail_user . '@' . GM_PRIMARY_MAIL_HOST; // change it to the primary host

        if (GSMTP_VERIFY_USERS) {
            // Here we can verify that the recipient is actually in the database.
            // Note that guerrillamail.com does not do this - you can send email
            // to a non-existing email address, and set to this email later.
            // just an example:
            if (array_pop(explode('@', $to)) !== 'sharklasers.com') {
                // check the user againts our user database
                $user = array_shift(explode('@', $to));
                $sql = "SELECT * FROM `gm2_address` WHERE `address_email`='" .
                    mysql_real_escape_string($user) . "@guerrillamailblock.com' ";
                $result = mysql_query($sql);
                if (mysql_num_rows($result) == 0) {
                    return false; // no such address
                }
            }
        }

        $hash = md5($to . $from . $subject . microtime());

        // add 'received' headers
        $add_head = '';
        $add_head .= "Delivered-To: " . $to . "\r\n";
        $add_head .= "Received: from " . $helo . " (" . $helo . "  [" . $helo_ip . "])\r\n";
        $add_head .= "	by " . GSMTP_HOST_NAME . " with SMTP id " . $hash . "@" .
            GSMTP_HOST_NAME . ";\r\n";
        $add_head .= "	" . gmdate('r') . "\r\n";

        $email = $add_head . $email;

        $Mem = get_memcache();
        if ($Mem->set($hash, $email, MEMCACHE_COMPRESSED, 4600) === true) {
            $email = '';
            $body = '';
        }
        $charset = '';
        $has_attach = '';
        $content_type = '';
        $sql = "INSERT INTO " . GM_MAIL_TABLE .
            " (`date`, `to`, `from`, `subject`, `body`, `charset`, `mail`, `spam_score`, `hash`, `content_type`, `recipient`, `has_attach` ) VALUES ('" .
            gmdate('Y-m-d H:i:s') . "', '" . mysql_real_escape_string($to) . "', '" .
            mysql_real_escape_string($from) . "', '" . mysql_real_escape_string($subject) .
            "',  '" . mysql_real_escape_string($body) . "', '" . mysql_real_escape_string($charset) .
            "', '" . mysql_real_escape_string($email) . "', 0" .
            ", '" . mysql_real_escape_string($hash) . "', '" . mysql_real_escape_string($content_type) .
            "', '" . mysql_real_escape_string($to) . "', '" . mysql_real_escape_string($has_attach) . "') ";

        mysql_query($sql) or log_line(mysql_error());
        $id = mysql_insert_id();
        if ($id) {

            $sql = "UPDATE gm2_setting SET `setting_value` = `setting_value`+1 WHERE `setting_name`='received_emails' LIMIT 1";
            mysql_query($sql);
        } else {
            log_line('Failed to save email From:' . $from . ' To:' . $to, 1);
        }

    }
    log_line('save_email() called, to:[' . $to . '] ID:' . $id);


    return array($hash, $to);
}

function get_email_headers($email, $header_names = array())
{

    $ret = array();
    $pos = strpos($email, "\r\n\r\n");
    $headers = substr($email, 0, $pos);
    $headers = explode("\r\n", $headers);

    foreach ($headers as $h) {

        foreach ($header_names as $i => $name) {

            if (stripos($h, $name . ': ') === 0) {
                $ret[$i] = substr($h, strlen($name) + 2);
            }

        }
    }

    return $ret;

}
