<?php

namespace VSAC;

/**
 * The Mandrill API driver for the emailer
 */

//----------------------------------------------------------------------------//
//-- Module required functions                                              --//
//----------------------------------------------------------------------------//

/** @see example_module_dependencies() */
function emailer_mandrill_depends()
{
    return array();
}


/** @see example_module_sysconfig() */
function emailer_mandrill_sysconfig()
{
    $path = config('emailer_mandrill_path', '');
    require_once $path;
    if (!class_exists('Mandrill')) {
        return 'Mandrill API library was not found';
    }
    return true;
}

/** @see example_module_config_options() */
function emailer_mandrill_config_items()
{
    return array(
        ['emailer_mandrill_path'   , '' , 'Path to the Mandrill PHP library', true],
        ['emailer_mandrill_api_key', '' , 'Your Mandrill API Key'           , true],
    );
}

//----------------------------------------------------------------------------//
//-- Implementation                                                         --//
//----------------------------------------------------------------------------//

/** @see emailer_force_test_conf */
function emailer_mandrill_force_test_conf()
{
    return load_test_conf(array(
        'emailer_mandrill_path'     => '',
        'emailer_mandrill_api_key'  => '',
    ), 'mandrill');
}


/** @see emailer_format_attachments */
function emailer_mandrill_format_attachments($attachments)
{
    return array_map(function ($attachment) {
        return array(
            'type'      => $attachment['content_type'],
            'name'      => $attachment['filename'],
            'content'   => $attachment['base64_content'],
        );
    }, $attachments);
}


/** @see emailer_send_single */
function emailer_mandrill_send_single(
    $to,
    $subject,
    $html,
    $text,
    array $merge_vars,
    array $attachments,
    array $embedded_images
) {
    $status = emailer_mandrill_send_batch(
        array($to),
        $subject,
        $html,
        $text,
        array($to => $merge_vars),
        $attachments,
        $embedded_images
    );
    if ($status['count']) {
        return true;
    }
    $err = array_shift($status['error']);
    return $err ? $err : 'Unknown error in emailer_mandrill_send_batch';
}


/** @see emailer_send_batch */
function emailer_mandrill_send_batch(
    array $to,
    $subject,
    $html,
    $text = '',
    array $merge_vars,
    array $attachments,
    array $embedded_images
) {

    emailer_mandrill_fix_merge_vars($subject, $html, $text);

    $message = array(
        'to'                    => array(),
        'merge_vars'            => array(),
        'html'                  => $html,
        'text'                  => $text,
        'subject'               => $subject,
        'from_email'            => config('emailer_default_from_addr', ''),
        'from_name'             => config('emailer_default_from_name', ''),
        'attachments'           => $attachments,
        'images'                => $embedded_images,
        'preserve_recipients'   => false,
        'headers'               => array(
            'Reply-To' => config('emailer_default_reply_to', ''),
        ),
        'merge'                 => true,
        'merge_language'        => 'mailchimp',
    );

    foreach ($to as $addr) {
        $mv = isset($merge_vars[$addr]) ? $merge_vars[$addr] : array();
        $recipient = array('email' => $addr);
        if (isset($mv['name'])) {
            $recipient['name'] = $mv['name'];
        }

        $_mv = array();
        foreach ($mv as $k => $v) {
            $_mv[] = array('name' => $k, 'content' => $v);
        }
        $message['to'][] = $recipient;
        if (!empty($_mv)) {
            $message['merge_vars'][] = array(
                'rcpt' => $addr,
                'vars' => $_mv,
            );
        }
    }
    $count = 0;
    $errors = [];
    try {
        $mandrill = emailer_mandrill_get();
        $mandrill->messages->send($message, true);
        $count = count($to);
    } catch (\Exception $e) {
        trigger_error($e->getMessage());
        $errors[] = $e->getMessage();
    }
    return compact('count', 'errors');
}

//----------------------------------------------------------------------------//
//-- Private methods                                                        --//
//----------------------------------------------------------------------------//

/**
 * Some incoming systems URL encode the merge var syntax, fix that.
 *
 * @private
 *
 * @param string $subject
 * @param string $html
 * @param string $text
 *
 * @return void
 */
function emailer_mandrill_fix_merge_vars(&$subject, &$html, &$text)
{
    $fix = function ($str) {
        return str_replace(['*%7C', '%7C*'],['*|', '|*'], $str);
    };
    $subject = $fix($subject);
    $html = $fix($html);
    $text = $fix($text);
}

/**
 * Get an instance of the Mandrill helper class distributed by MailChimp,
 * loads the class.
 *
 * @return \Mandrill
 */
function emailer_mandrill_get()
{
    static $mandrill;
    if (is_null($mandrill)) {
        $path = config('emailer_mandrill_path', '');
        $key = config('emailer_mandrill_api_key', '');
        require_once $path;
        $mandrill = new \Mandrill($key);
    }
    return $mandrill;
}



