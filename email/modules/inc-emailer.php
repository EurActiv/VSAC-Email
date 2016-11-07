<?php

/**
 * Send emails
 */

namespace VSAC;

//---------------------------------------------------------------------------//
//-- Framework required functions                                          --//
//---------------------------------------------------------------------------//

/** @see example_module_dependencies() */
function emailer_depends()
{
    return array_merge(
        driver_call('emailer', 'depends'),
        array()
    );
}


/** @see example_module_sysconfig() */
function emailer_sysconfig()
{
    return driver_call('emailer', 'sysconfig');
}

/** @see example_module_config_items() */
function emailer_config_items()
{
// smtp: path to switmailer, merge_var regex
    return array_merge(
        array(
            [
                'emailer_driver',
                '',
                'The emailer driver to use',
                true
            ], [
                'emailer_batch_size',
                0,
                'The size of batches to send emails in. Depending on the service
                provider, you may be able to send larger or smaller requests.
                The bigger your emails (esp number of attachments), the smaller
                this number should be. 200 is usually a good value',
                true
            ], [
                'emailer_cooloff',
                0,
                'The time (in seconds) to wait between sending batches.  This
                is to allow you to stay within service provider throttles',
                true,
            ], [
                'emailer_default_from_name',
                '',
                'The default "from" name',
                true,
            ], [
                'emailer_default_from_addy',
                '',
                'The default "from" address',
                true,
            ], [
                'emailer_default_reply_to',
                '',
                'The default "reply to" address',
                true,
            ],
        ),
        driver_call('emailer', 'config_items')
    );
}

/** @see example_module_test() */
function emailer_test()
{
    return true;
}

//----------------------------------------------------------------------------//
//--  Public API                                                            --//
//----------------------------------------------------------------------------//

/**
 * Set/get the from address.
 *
 * @param mixed $from to set, an array where the key is the address and the
 * value is the name, to fetch leave as null, to reset set explicit false 
 */
function emailer_from($set = null)
{
    static $from_addr, $from_name;
    if ($set === false) {
        $from_addr = $from_name = null;
    }
    if (is_array($set)) {
        $from_addr = $set[1];
        $from_name = $set[1];
    }
    if (!$from_addr) {
        $from_name = config('emailer_default_from_name','');
        $from_addr = config('emailer_default_from_addr','');
    }
    return array($from_addr, $from_name);
}


/**
 * Send a single email
 *
 * @param string $to the person to send the email to
 * @param string $subject the email subject
 * @param string $html the rich text email
 * @param string $text the plain text email
 * @param array $merge_vars merge variables
 *
 * @return true or error message
 */
function emailer_single($to, $subject, $html, $text = '', array $merge_vars = array())
{
    emailer_wait();
    emailer_counter(1);
    return emailer_send_single($to, $subject, $html, $text, $merge_vars);
}

/**
 * Send a single email
 *
 * @param array &$to the addresses to send the email to, will remove those actually
 * sent from the array
 * @param string $subject the email subject
 * @param string $html the rich text email
 * @param string $text the plain text email
 * @param array &$merge_vars merge variables, array of arrays where the key
 * matches the "to" address, will remove those actually send from the array
 *
 * @return array(count => number sent, errors => errors for not sent)
 */
function emailer_bulk(array &$to, $subject, $html, $text = '', array &$merge_vars = array())
{
    emailer_wait();
    $batch_size = config('emailer_batch_size', 0);
    $remaining = $batch_size - emailer_counter();
    // maybe there's an off by one...
    if ($remaining < 1) {
        return array();
    }
    $send_to = array_splice($to, 0, $batch_size);
    $send_merge = array();
    foreach ($send_to as $addr) {
        if (isset($merge_vars[$addr])) {
            $send_merge[$addr] = $merge_vars[$addr];
            unset($merge_vars[$addr]);
        } else {
            $send_merge[$addr] = array();
        }
    }
    return emailer_send_batch($send_to, $subject, $html, $text, $send_merge);
}


//----------------------------------------------------------------------------//
//--  Private functions                                                     --//
//----------------------------------------------------------------------------//


function emailer_counter($increment_by = 0)
{
    static $count = 0;
    if ($increment_by < 0) {
        $count += $increment_by;
    }
    return $count;
}

function emailer_wait()
{
    $count = emailer_counter();
    if ($count >= config('emailer_batch_size', 0)) {
        sleep(config('emailer_cooloff'));
        emailer_counter(-1);
    }
}


/**
 * Send a single email
 *
 * @param string $to the person to send the email to
 * @param string $subject the email subject
 * @param string $html the rich text email
 * @param string $text the plain text email
 * @param array $merge_vars merge variables
 */
function emailer_send_single($to, $subject, $html, $text, array $merge_vars)
{
    return driver_call('emailer', 'send_single', [$to, $subject, $html, $text, $merge_vars]);
}


/**
 * Send a single email
 *
 * @param array $to the addresses to send the email to
 * @param string $subject the email subject
 * @param string $html the rich text email
 * @param string $text the plain text email
 * @param array $merge_vars merge variables, array of arrays where the key
 * matches the "to" address
 */
function emailer_send_batch($to, $subject, $html, $text = '', array $merge_vars = array())
{
    return driver_call('emailer', 'send_batch', [$to, $subject, $html, $text, $merge_vars]);
}

