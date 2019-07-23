<?php

/**
 * @author SafeCharge
 * @year 2019
 */
class SC_LOGGER
{
    /**
     * Function create_log
     * Create logs. You MUST have defined SC_LOG_FILE_PATH const,
     * holding the full path to the log file.
     * 
     * @param mixed $data
     * @param string $title - title of the printed log
     */
    public static function create_log($data, $title = '')
    {
        if(
            @$_REQUEST['create_logs'] == 'yes' || @$_REQUEST['create_logs'] == 1
            || @$_SESSION['create_logs'] == 'yes' || @$_SESSION['create_logs'] == 1
        ) {
            // same for all plugins
            $d = $data;

            if(is_array($data)) {
                if(isset($data['cardData']) && is_array($data['cardData'])) {
                    foreach($data['cardData'] as $k => $v) {
                        $data['cardData'][$k] = 'some data';
                    }
                }
                if(isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
                    foreach($data['userAccountDetails'] as $k => $v) {
                        $data['userAccountDetails'][$k] = 'some data';
                    }
                }
                if(isset($data['userPaymentOption']) && is_array($data['userPaymentOption'])) {
                    foreach($data['userPaymentOption'] as $k => $v) {
                        $data['userPaymentOption'][$k] = 'some data';
                    }
                }
                if(isset($data['paResponse']) && !empty($data['paResponse'])) {
                    $data['paResponse'] = 'a long string';
                }
                if(isset($data['paReq']) && !empty($data['paReq'])) {
                    $data['paReq'] = 'a long string';
                }
                
                $d = print_r($data, true);
            }
            elseif(is_object($data)) {
                $d = print_r($data, true);
            }
            elseif(is_bool($data)) {
                $d = $data ? 'true' : 'false';
            }

            if(!empty($title)) {
                $d = $title . "\r\n" . $d;
            }
            // same for all plugins
        
            $logs_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;

            if(is_dir($logs_path)) {
                try {
                    file_put_contents(
                        $logs_path . date('Y-m-d', time()) . '.txt',
                        date('H:i:s') . ': ' . $d . "\r\n", FILE_APPEND
                    );
                }
                catch (Exception $exc) {}
            }
        }
    }
}
