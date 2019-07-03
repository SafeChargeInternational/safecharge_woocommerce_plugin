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
            !isset($_SESSION['SC_Variables']['save_logs'])
            || $_SESSION['SC_Variables']['save_logs'] == 'no'
            || $_SESSION['SC_Variables']['save_logs'] === null
        ) {
            return;
        }
        
        $d = '';
        
        if(is_array($data)) {
            if(isset($data['cardData']) && is_array($data['cardData'])) {
                foreach($data['cardData'] as $k => $v) {
                    $data['cardData'][$k] = md5($v);
                }
            }
            if(isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
                foreach($data['userAccountDetails'] as $k => $v) {
                    $data['userAccountDetails'][$k] = md5($v);
                }
            }
            if(isset($data['paResponse']) && !empty($data['paResponse'])) {
                $data['paResponse'] = 'a long string';
            }
            if(isset($data['paRequest']) && !empty($data['paRequest'])) {
                $data['paResponse'] = 'a long string';
            }

            $d = print_r($data, true);
        }
        elseif(is_object($data)) {
            $d = print_r($data, true);
        }
        elseif(is_bool($data)) {
            $d = $data ? 'true' : 'false';
        }
        else {
            $d = $data;
        }
        
        if(!empty($title)) {
            $d = $title . "\r\n" . $d;
        }
        
        $logs_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        
        if(is_dir($logs_path)) {
            try {
                file_put_contents(
                    $logs_path . date('H:i:s') . '.txt',
                    date('H:i:s') . ': ' . $d . "\r\n"."\r\n", FILE_APPEND
                );
            }
            catch (Exception $exc) {}
        }
    }
}
