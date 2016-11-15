<?php

namespace VSAC;

/**
 * The SMTP driver for the emailer.
 *
 * WARNING: SMTP is inherently slow; don't use this if you're sending thousands
 * of emails that have to arrive more or less at the same time.
 */

//----------------------------------------------------------------------------//
//-- Module required functions                                              --//
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
        ['emailer_smtp_phpmailer_path', '', 'Path to PHPMailer'    , true],
        ['emailer_smtp_server'  , ''    , 'Your SMTP server'       , true],
        ['emailer_smtp_port'    , 0     , 'your emailer smtp port' , true],
        ['emailer_smtp_user'    , ''    , 'The SMTP user'          , true],
        ['emailer_smtp_pass'    , ''    , 'The SMTP password'      , true],
        ['emailer_smtp_auth'    , true  , 'Use SMTP auth'          , true],
        [
            'emailer_smtp_secure',
            '',
            'Transport layer security, "tls" (prefered), "ssl" or "none" (avoid)',
            true
        ], [
            'emailer_smtp_merge_var_regex',
            '',
            'A regular expression to match merge variables to placeholders. The
            first substring match is considered the variable name. For example,
            a mailchimp-like syntax would be "/\*\|([A-Z_]+)\|\*/i".'
        ],
    );
}


//----------------------------------------------------------------------------//
//-- Implementation                                                         --//
//----------------------------------------------------------------------------//

/** @see emailer_force_test_conf */
function emailer_smtp_force_test_conf()
{
    force_conf('emailer_smtp_merge_var_regex', '/\*\|([A-Z_]+)\|\*/i');
    return load_test_conf(array(
        'emailer_smtp_phpmailer_path'   => '',
        'emailer_smtp_server'           => '',
        'emailer_smtp_port'             => 0,
        'emailer_smtp_user'             => '',
        'emailer_smtp_pass'             => '',
        'emailer_smtp_auth'             => true,
        'emailer_smtp_secure'           => '',
    ), 'smtp');
}


/** @see emailer_format_attachments */
function emailer_smtp_format_attachments($attachments)
{
    $tmp_dir = sys_get_temp_dir() . '/vsac-phpmailer/';
    if (!is_dir($tmp_dir)) {
        mkdir($tmp_dir);
    }

    return array_map(function ($attachment) use ($tmp_dir) {
        extract($attachment, EXTR_SKIP);
        $tmp_file = $tmp_dir . md5($base64_content) . '-' . $filename;
        file_put_contents($tmp_file, base64_decode($base64_content));
        register_shutdown_function(function () use ($tmp_file) {
            if (file_exists($tmp_file)) {
                unlink($tmp_file);
            }
        });

        return array('path' => $tmp_file, 'name' => $filename);
    }, $attachments);
}

/** @see emailer_send_single */
function emailer_smtp_send_single(
    $to,
    $subject,
    $html,
    $text,
    array $merge_vars,
    array $attachments,
    array $embedded_images
) {
    emailer_smtp_load_phpmailer();
    emailer_smtp_merge_vars($html, $text, $merge_vars);
    $mail = new \PHPMailer;
    $mail->isSMTP();

    $mail->Host         = config('emailer_smtp_server'  , ''    );
    $mail->SMTPAuth     = config('emailer_smtp_auth'    , true  );
    $mail->Username     = config('emailer_smtp_user'    , ''    );
    $mail->Password     = config('emailer_smtp_pass'    , ''    );
    $mail->SMTPSecure   = config('emailer_smtp_secure'  , ''    );
    $mail->Port         = config('emailer_smtp_port'    , 0     );

    list($from_addr, $from_name) = emailer_from();
    $mail->setFrom($from_addr, $from_name);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = $text;

    foreach ($attachments as $attach) {
        $mail->addAttachment($attach['path'], $attach['name']);
    }
    foreach ($embedded_images as $image) {
        $mail->AddEmbeddedImage($image['path'], $image['name'], $image['name']);
    }

    $result = $mail->send() ? true : 'Mailer Error: ' . $mail->ErrorInfo;
    return $result;
}


/** @see emailer_send_batch */
function emailer_smtp_send_batch(
    array $to,
    $subject,
    $html,
    $text,
    array $merge_vars,
    array $attachments,
    array $embedded_images
) {
    $count = 0;
    $errors = array();
    foreach ($to as $addr) {
        $result = emailer_smtp_send_single(
            $addr,
            $subject,
            $html,
            $text,
            $merge_vars[$addr],
            $attachments,
            $embedded_images
        );
        if ($result === true) {
            $count += 1;
        } else {
            $errors[$addr] = $result;
        }
    }
    return compact('count', 'errors');
}


//----------------------------------------------------------------------------//
//-- Private methods                                                        --//
//----------------------------------------------------------------------------//

/**
 * Require the PHPMailer autoloader and make sure the class loads.
 *
 * @private
 *
 * @return bool the class is loaded, or not
 */
function emailer_smtp_load_phpmailer()
{
    static $loaded = false;
    if ($loaded) return true;
    $path = config('emailer_smtp_phpmailer_path', '');
    require_once $path;
    $loaded = class_exists('PHPMailer');
    return $loaded;
}


/**
 * Apply merge variables to an email before sending; used because SMTP backend
 * will not have any merge variable capability built in
 *
 * @param string &$html
 * @param string &$text
 * @param array $merge_vars
 */
function emailer_smtp_merge_vars(&$html, &$text, array $merge_vars)
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
}

