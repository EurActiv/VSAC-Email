<?php

namespace VSAC;

/**
 * The NOOP driver for the emailer
 */

//----------------------------------------------------------------------------//
//-- Implementation                                                         --//
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

/** @see emailer_send_single */
function emailer_noop_send_single($to, $subject, $html, $text, array $merge_vars)
{
}


/** @see emailer_send_batch */
function emailer_noop_send_batch($to, $subject, $html, $text = '', array $merge_vars = array())
{
}

