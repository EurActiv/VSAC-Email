<?php

namespace VSAC;

/**
 * The Mandrill API driver for the emailer
 */

//----------------------------------------------------------------------------//
//-- Implementation                                                         --//
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

/** @see emailer_send_single */
function emailer_mandrill_send_single($to, $subject, $html, $text, array $merge_vars)
{
    $merge_vars = array($to => $merge_vars);
    $to = array($to);
    return emailer_mandrill_send_batch($to, $subject, $html, $text, $merge_vars); 
}


/** @see emailer_send_batch */
function emailer_mandrill_send_batch(array $to, $subject, $html, $text = '', array $merge_vars = array())
{
    $merge_var_fix = function ($str) {
        return str_replace(['*%7C', '%7C*'],['*|', '|*'], $str);
    };
    $message = array(
        'to' => array(),
        'merge_vars' => array(),
        'html'    => $merge_var_fix($html),
        'text'    => $merge_var_fix($text),
        'subject' => $merge_var_fix($subject),
        'from_email' => config('emailer_default_from_addy', ''),
        'from_name' => config('emailer_default_from_name', ''),
        'preserve_recipients' => false,
        'headers' => array(
            'Reply-To' => config('emailer_default_reply_to', ''),
        ),
        'merge' => true,
        'merge_language' => 'mailchimp',
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



