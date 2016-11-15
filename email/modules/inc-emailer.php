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
        array('mime')
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
                'emailer_default_from_addr',
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
    use_module('http');
    force_conf('emailer_batch_size', 2);
    force_conf('emailer_cooloff', 0);
    $driver = driver('emailer');
    $test_conf_err = load_test_conf(array(
        'emailer_default_from_name' => '',
        'emailer_default_from_addr' => '',
        'emailer_default_reply_to'  => '',
        'emailer_test_to'           => array(),
    ), $driver);
    if ($test_conf_err) {
        return $test_conf_err;
    }
    if ($test_config_err = emailer_force_test_conf()) {
        return $test_config_err;
    }

    $get_file = function ($src) {
        $file = http_get($src);
        $content_type = $file['headers']['Content-Type'];
        $content = $file['body'];
        $filename = basename($src) . '.' . mime_to_ext($content_type);
        return compact('content_type', 'content', 'filename');
    };

    $attachments = array(
        $get_file('https://httpbin.org/image/jpeg'),
        $get_file('https://httpbin.org/image/svg'),
        $get_file('https://httpbin.org/image/png'),
    );
    $embedded = array_shift($attachments);
    $embedded = 'data:' . $embedded['content_type'] . ';base64,'
              . base64_encode($embedded['content']);

    $tos = config('emailer_test_to', array());
    $sub = 'VSAC Emailer test: single ' . $driver;
    $htm = '<p>Unit test. <a href="http://example.com">This is a link</a></p>'
         . '<p>This is a merge var: *|NAME|*.</p>'
         . '<p><img src="' . $embedded . '" /></p>';
    $txt = 'Unit test, alt text body';
    $mvs = array_fill_keys($tos, array('name' => 'Test Name'));

    $result = emailer_single($tos[0], $sub, $htm, $txt, $mvs[$tos[0]], $attachments);
    if ($result !== true) {
        return sprintf(
            'emailer_single failed; expected TRUE, got "%s"',
            var_export($result, true)
        );
    }

    $sub = 'VSAC Emailer test: bulk ' . $driver;
    $count_tos = count($tos);
    $result = emailer_bulk($tos, $sub, $htm, $txt, $mvs, $attachments);
    if (!empty($result['errors'])) {
        return 'emailer_bulk reported errors: ' . implode(' /// ', $result['errors']);
    }
    if ($result['count'] !== 2) {
        return sprintf(
            'emailer_bulk failed; expected to send 2, sent "%s"',
            var_export($expected, true),
            var_export($result, true)
        );
    }
    if ((count($tos) + 2) != $count_tos) {
        return 'Error in emailer_bulk: did not pop off send email addresses';
    }

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
 * @param array $attachments @see emailer_normalize_attachments() for format
 *
 * @return true or error message
 */
function emailer_single(
    $to,
    $subject,
    $html,
    $text = '',
    array $merge_vars = array(),
    array $attachments = array()
) {
    emailer_wait();
    emailer_counter(1);
    $embedded_images = emailer_prepare_media($html, $attachments);

    return emailer_send_single(
        $to,
        $subject,
        $html,
        $text,
        $merge_vars,
        $attachments,
        $embedded_images
    );
}

/**
 * Send a bulk email
 *
 * @param array &$to the addresses to send the email to, will remove those actually
 * sent from the array
 * @param string $subject the email subject
 * @param string $html the rich text email
 * @param string $text the plain text email
 * @param array &$merge_vars merge variables, array of arrays where the key
 * matches the "to" address, will remove those actually send from the array
 * @param array $attachments @see emailer_normalize_attachments() for format
 *
 * @return array(count => number sent, errors => errors for not sent)
 */
function emailer_bulk(
    array &$to,
    $subject,
    $html,
    $text = '',
    array &$merge_vars = array(),
    array $attachments = array()
) {
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
    $embedded_images = emailer_prepare_media($html, $attachments);
    return emailer_send_batch(
        $send_to,
        $subject,
        $html,
        $text,
        $send_merge,
        $attachments,
        $embedded_images
    );
}


//----------------------------------------------------------------------------//
//--  Private functions                                                     --//
//----------------------------------------------------------------------------//

/**
 * Prepare media to in a standardized format; normalize attachments, convert
 * data urls to attachments
 *
 * @param string &$html the html body of the message
 * @param array &$attachments the email attachments
 *
 * @return array images that will need to be embedded
 */
function emailer_prepare_media(&$html, array &$attachments)
{
    $attachments = emailer_normalize_attachments($attachments);
    $attachments = emailer_format_attachments($attachments);

    $embedded_images = array();
    $html = preg_replace_callback(
        '/src=[\'"]data:([a-z\/\-]+);base64,([^\'"]+)[\'"]/i',
        function ($data_url) use (&$embedded_images) {
            $img = array(
                'content_type'      => $data_url[1],
                'base64_content'    => $data_url[2],
                'filename'          => uniqid() . '.' . mime_to_ext($data_url[1]),
            );
            $embedded_images[] = $img;
            return sprintf('src="cid:%s"', $img['filename']);
        },
        $html
    );
    $embedded_images = emailer_format_attachments($embedded_images);
    return $embedded_images;
}


/**
 * Count the total number of emails sent since the last cooloff
 *
 * @param integer $increment_by increment by this number, or a negative to reset
 *
 * @return integer the current count
 */
function emailer_counter($increment_by = 0)
{
    static $count = 0;
    if ($increment_by < 0) {
        $count += $increment_by;
    }
    return $count;
}

/**
 * Sleep for the cooloff period if necessary
 *
 * @return void
 */
function emailer_wait()
{
    $count = emailer_counter();
    if ($count >= config('emailer_batch_size', 0)) {
        sleep(config('emailer_cooloff'));
        emailer_counter(-1);
    }
}

/**
 * Normalize attachments.  Always outputs the format:
 *
 *     array(
 *         array(
 *             'filename' => "{$name}.{$ext}",
 *             'base64_content' => base64 encoded content
 *             'content_type' => the MIME type (eg, image/jpeg)
 *        )
 *        // ...
 *    );
 *
 * Input attachments may be either an array or an absolute path to a local file.
 *
 * If input attachments are an array, they must have the format keys:
 *   - 'base64_content', containing the file contents, base64 encoded, OR
 *     just 'content', containing the binary file contents.
 *   - "filename", with extension
 *   - "content_type", with the mime type
 * If either 'filename' or 'content_type' is missing, it will be calculated from
 * the other; however one of the two must be set.
 *
 * @param array $attachments
 *
 * @return array 
 */
function emailer_normalize_attachments(array $attachments)
{

    return array_filter(array_map(
        function ($attachment) {
            if (is_string($attachment)) {
                $content = file_get_contents($attachment);
                if (!$content) {
                    trigger_error('Could not find attachment file');
                    return false;
                }
                return array(
                    'base64_content' => base64_encode($content),
                    'content_type'   => mime_detect_file($attachment),
                    'filename'       => basename($attachment),
                );
            }
            if (is_array($attachment)) {
                extract($attachment);
                if (!empty($content)) {
                    $base64_content = base64_encode($content);
                }
                if (empty($base64_content)) {
                    trigger_error('Neither "content" nor "base64_content" is set');
                    return false;
                }
                if (empty($filename) && empty($content_type)) {
                    trigger_error('Neither "filename" nor "content_type" is set');
                    return false;
                }
                if (empty($filename)) {
                    $filename = md5($base64_content) . '.' . mime_to_ext($content_type);
                }
                if (empty($content_type)) {
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    if (!$ext) {
                        trigger_error('Filename does not have an extension');
                        return false;
                    }
                    $content_type = mime_from_ext($ext);
                }
                return compact('base64_content', 'content_type', 'filename');
            }
            trigger_error('Malformatted attachment');
            return false;        
        },
        $attachments
    ));
}

/**
 * Stub for drivers to load their testing configuration before running
 *
 * @return string any configuration errors encountered
 */
function emailer_force_test_conf()
{
    return driver_call('emailer', 'force_test_conf');
}

/**
 * Stub for drivers to format attachments and embedded images before sending to
 * their backend
 *
 * @param array $attachments the attachments to format
 *
 * @return array
 */
function emailer_format_attachments(array $attachments)
{
    return driver_call('emailer', 'format_attachments', [$attachments]);
}

/**
 * Send a single email
 *
 * @param string $to the person to send the email to
 * @param string $subject the email subject
 * @param string $html the rich text email
 * @param string $text the plain text email
 * @param array $merge_vars merge variables
 * @param array $attachments
 * @param array $embedded_images
 */
function emailer_send_single(
    $to,
    $subject,
    $html,
    $text,
    array $merge_vars,
    array $attachments,
    array $embedded_images
) {
    return driver_call(
        'emailer',
        'send_single',
        [$to, $subject, $html, $text, $merge_vars, $attachments, $embedded_images]
    );
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
 * @param array $attachments
 * 
 */
function emailer_send_batch(
    $to,
    $subject,
    $html,
    $text = '',
    array $merge_vars,
    array $attachments,
    array $embedded_images
) {
    return driver_call(
        'emailer',
        'send_batch',
        [$to, $subject, $html, $text, $merge_vars, $attachments, $embedded_images]
    );
}

