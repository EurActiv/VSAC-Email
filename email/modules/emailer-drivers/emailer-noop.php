<?php

namespace VSAC;

/**
 * The NOOP driver for the emailer
 */

//----------------------------------------------------------------------------//
//-- Module required functions                                              --//
//----------------------------------------------------------------------------//


/** @see example_module_dependencies() */
function emailer_noop_depends()
{
    return array();
}


/** @see example_module_sysconfig() */
function emailer_noop_sysconfig()
{
    return true;
}

/** @see example_module_config_options() */
function emailer_noop_config_items()
{
    return array();
}

//----------------------------------------------------------------------------//
//-- Implementation                                                         --//
//----------------------------------------------------------------------------//


/** @see emailer_force_test_conf */
function emailer_noop_force_test_conf()
{
    return false;
}

/** @see emailer_format_attachments */
function emailer_noop_format_attachments($attachments)
{
    return $attachments;
}

/** @see emailer_send_single */
function emailer_noop_send_single(
    $to,
    $subject,
    $html,
    $text,
    array $merge_vars,
    array $attachments
){
    return true;
}


/** @see emailer_send_batch */
function emailer_noop_send_batch(
    $to,
    $subject,
    $html,
    $text = '',
    array $merge_vars,
    array $attachments
) {
    return array('count' => count($to), 'errors' => array());
}

