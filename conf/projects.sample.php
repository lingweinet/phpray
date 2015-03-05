<?php
/**
 * Created by PhpStorm.
 * User: panzd
 * Date: 15/2/25
 * Time: 下午2:30
 */

return array(
    array(
        'name' => 'php_library',
        'src' => '/The/Path/Of/Project/src',
        'init' => function($project) {
            require_once dirname($project['src']) . '/vendor/autoload.php';
        },
        'logInterceptions' => array(
            array(
                "method" => "error_log",
                "callback" => function($message, $message_type = 0, $destination = null, $extra_headers = null) {
                    return $message;
                }
            ),
        )
    ),
);
