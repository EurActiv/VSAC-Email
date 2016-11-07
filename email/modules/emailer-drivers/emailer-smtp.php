<?php

namespace VSAC;

/**
 * The SMTP driver for the emailer
 */

//----------------------------------------------------------------------------//
//-- Implementation                                                         --//
//----------------------------------------------------------------------------//

/** @see example_module_dependencies() */
function emailer_smtp_depends()
{
    return array();
}


/** @see example_module_sysconfig() */
function emailer_smtp_sysconfig()
{
    if (!emailer_smtp_load_phpmailer()) {
        return 'PHPMailer was not found';
    }
    return true;
}

/** @see example_module_config_options() */
function emailer_smtp_config_items()
{
    return array(
        ['emailer_smtp_server'  , ''    , 'Your SMTP server'       , true],
        ['emailer_smtp_port'    , 0     , 'your emailer smtp port' , true],
        ['emailer_smtp_user'    , ''    , 'The SMTP user'          , true],
        ['emailer_smtp_pass'    , ''    , 'The SMTP password'      , true],
        ['emailer_smtp_auth'    , ''    , 'The SMTP authmode'      , true],
        ['emailer_smtp_tls'     , true  , 'Use TLS transport layer', true],
        [
            'emailer_smtp_merge_var_regex',
            '',
            'A regular expression to match merge variables to placeholders. The
            first substring match is considered the variable name. For example,
            a mailchimp-like syntax would be "/\*\|([A-Z_]+)\|\*/i".'
        ],
    );
}

/** @see emailer_send_single */
function emailer_smtp_send_single($to, $subject, $html, $text, array $merge_vars)
{

    emailer_smtp_load_phpmailer();
    $mail = new \PHPMailer;
    $mail->isSMTP();
    $mail->Host = config('emailer_smtp_server', '');
    $mail->SMTPAuth = true;
    $mail->Username = config('emailer_smtp_user', '');
    $mail->Password = config('emailer_smtp_pass', '');
    $mail->SMTPSecure = 'tls';
    $mail->Port = config('emailer_smtp_port', 0);
    list($from_addr, $from_name) = emailer_from();
    $mail->setFrom($from_addr, $from_name);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = $text;

    if($mail->send()) {
        return true;
    }
    return 'Mailer Error: ' . $mail->ErrorInfo;
}


/** @see emailer_send_batch */
function emailer_smtp_send_batch(array $to, $subject, $html, $text = '', array $merge_vars = array())
{
    $count = 0;
    $errors = array();
    foreach ($to as $addr) {
        $result = emailer_smtp_send_single($addr, $subject, $html, $text, $merge_vars[$addr]);
        if ($result === true) {
            $count += 1;
        } else {
            $errors[$addr] = $result;
        }
    }
    return compact('count', 'errors');
}


function emailer_smtp_load_phpmailer()
{
    static $loaded = false;
    if ($loaded) return true;
    $path = config('emailer_smtp_phpmailer_path', '');
    require_once $path;
    $loaded = class_exists('PHPMailer');
    return $loaded;
}

function emailer_smtp_merge_vars($html, $text, $merge_vars)
{
    $mv_keys = array_keys($merge_vars);
    $mv_vals = array_values($merge_vars);
    $mv_keys = array_map('strtolower', $mv_keys);
    $merge_vars = array_combine($mv_keys, $mv_vals);
    $regex = config('emailer_smtp_merge_var_regex', '');
    $callback = function ($m) use ($merge_vars) {
        $string = strtolower($m[1]);
        return isset($merge_vars[$string]) ? $merge_vars[$string] : $m[0];
    };
    $html = preg_replace_callback($regex, $callback, $html);
    $text = preg_replace_callback($regex, $callback, $text);
    return array($html, $text);
}

