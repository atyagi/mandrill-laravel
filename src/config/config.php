<?php

return array(
    
    /*
    |--------------------------------------------------------------------------
    | Sender email address
    |--------------------------------------------------------------------------
    */
    'from_email' => '',

    /*
    |--------------------------------------------------------------------------
    | Other global level configs for the messages struct of the Mandrill API, not
    | including 'merge_vars', 'to', or 'from_email'. This applies for all
    | messages that will be sent
    |--------------------------------------------------------------------------
    */
    'global_configs' => array(),

    /*
    |--------------------------------------------------------------------------
    | Logic for mapping template data out of objects
    |--------------------------------------------------------------------------
    | Example structure:
    | 'content' => array(
    |   '<email_template_name>' => array(
    |       'email' => function to return the 'to' address for the email
    |       'merge_vars' => array(
    |           '<variable name>' => function to return said variable name
    |           ...
    |       ),
    |       'custom' => array( note: this overrides any global configs
    |           '<api-field>' => Mandrill Template API value for key
    |           ...
    |       )
    |   ),
    |   ...
    | )
    */

    'content' => array()


);
