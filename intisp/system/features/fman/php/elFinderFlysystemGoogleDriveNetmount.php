<?php

/*
 * Adaclare IntISP System
 * Copyright Adaclare Technologies 2007-2018
 * https://www.adaclare.com
 * https://github.com/INTisp
 *
 */

use \Hypweb\Flysystem\Cached\Extra\DisableEnsureParentDirectories;
use \Hypweb\Flysystem\Cached\Extra\Hasdir;
use \Hypweb\Flysystem\GoogleDrive\GoogleDriveAdapter;
use \League\Flysystem\Adapter\Local;
use \League\Flysystem\Cached\CachedAdapter;
use \League\Flysystem\Cached\Storage\Adapter as ACache;
use \League\Flysystem\Filesystem;

elFinder::$netDrivers['googledrive'] = 'FlysystemGoogleDriveNetmount';

if (!class_exists('elFinderVolumeFlysystemGoogleDriveCache', FALSE)) {
    class elFinderVolumeFlysystemGoogleDriveCache extends ACache
    {
        use Hasdir;
        use DisableEnsureParentDirectories;
    }
}

class elFinderVolumeFlysystemGoogleDriveNetmount extends \Barryvdh\elFinderFlysystemDriver\Driver
{
    public function __construct()
    {
        parent::__construct();

        $opts = [
            'rootCssClass'   => 'elfinder-navbar-root-googledrive',
            'gdAlias'        => '%s@GDrive',
            'gdCacheDir'     => __DIR__.'/.tmp',
            'gdCachePrefix'  => 'gd-',
            'gdCacheExpire'  => 600,
        ];

        $this->options = array_merge($this->options, $opts);
    }

    /**
     * Prepare driver before mount volume.
     * Return true if volume is ready.
     *
     * @return bool
     **/
    protected function init()
    {
        if (empty($this->options['icon'])) {
            $this->options['icon'] = TRUE;
        }
        if ($res = parent::init()) {
            if ($this->options['icon'] === TRUE) {
                unset($this->options['icon']);
            }
        }

        return $res;
    }

    /**
     * Prepare
     * Call from elFinder::netmout() before volume->mount().
     *
     * @return array
     *
     * @author Naoki Sawada
     **/
    public function netmountPrepare($options)
    {
        if (empty($options['client_id']) && defined('ELFINDER_GOOGLEDRIVE_CLIENTID')) {
            $options['client_id'] = ELFINDER_GOOGLEDRIVE_CLIENTID;
        }
        if (empty($options['client_secret']) && defined('ELFINDER_GOOGLEDRIVE_CLIENTSECRET')) {
            $options['client_secret'] = ELFINDER_GOOGLEDRIVE_CLIENTSECRET;
        }

        if (!isset($options['pass'])) {
            $options['pass'] = '';
        }

        try {
            $client = new \Google_Client();
            $client->setClientId($options['client_id']);
            $client->setClientSecret($options['client_secret']);

            if ($options['pass'] === 'reauth') {
                $options['pass'] = '';
                $this->session->set('GoogleDriveAuthParams', [])->set('GoogleDriveTokens', []);
            } elseif ($options['pass'] === 'googledrive') {
                $options['pass'] = '';
            }

            $options = array_merge($this->session->get('GoogleDriveAuthParams', []), $options);

            if (!isset($options['access_token'])) {
                $options['access_token'] = $this->session->get('GoogleDriveTokens', []);
                $this->session->remove('GoogleDriveTokens');
            }
            $aToken = $options['access_token'];

            $rootObj = $service = NULL;
            if ($aToken) {
                try {
                    $client->setAccessToken($aToken);
                    if ($client->isAccessTokenExpired()) {
                        $aToken = array_merge($aToken, $client->fetchAccessTokenWithRefreshToken());
                        $client->setAccessToken($aToken);
                    }
                    $service = new \Google_Service_Drive($client);
                    $rootObj = $service->files->get('root');

                    $options['access_token'] = $aToken;
                    $this->session->set('GoogleDriveAuthParams', $options);
                } catch (Exception $e) {
                    $aToken                  = [];
                    $options['access_token'] = [];
                    if ($options['user'] !== 'init') {
                        $this->session->set('GoogleDriveAuthParams', $options);

                        return ['exit' => TRUE, 'error' => elFinder::ERROR_REAUTH_REQUIRE];
                    }
                }
            }

            if ($options['user'] === 'init') {
                if (empty($options['url'])) {
                    $options['url'] = $this->getConnectorUrl();
                }

                $callback = $options['url']
                           .'?cmd=netmount&protocol=googledrive&host=1';
                $client->setRedirectUri($callback);

                if (!$aToken && empty($_GET['code'])) {
                    $client->setScopes([Google_Service_Drive::DRIVE]);
                    if (!empty($options['offline'])) {
                        $client->setApprovalPrompt('force');
                        $client->setAccessType('offline');
                    }
                    $url = $client->createAuthUrl();

                    $html = '<input id="elf-volumedriver-googledrive-host-btn" class="ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only" value="{msg:btnApprove}" type="button" onclick="window.open(\''.$url.'\')">';
                    $html .= '<script>
                        $("#'.$options['id'].'").elfinder("instance").trigger("netmount", {protocol: "googledrive", mode: "makebtn"});
                    </script>';
                    if (empty($options['pass']) && $options['host'] !== '1') {
                        $options['pass'] = 'return';
                        $this->session->set('GoogleDriveAuthParams', $options);

                        return ['exit' => TRUE, 'body' => $html];
                    }  
                        $out = [
                            'node' => $options['id'],
                            'json' => '{"protocol": "googledrive", "mode": "makebtn", "body" : "'.str_replace($html, '"', '\\"').'", "error" : "'.elFinder::ERROR_ACCESS_DENIED.'"}',
                            'bind' => 'netmount',
                        ];

                        return ['exit' => 'callback', 'out' => $out];
                    
                }  
                    if (!empty($_GET['code'])) {
                        $aToken                  = $client->fetchAccessTokenWithAuthCode($_GET['code']);
                        $options['access_token'] = $aToken;
                        $this->session->set('GoogleDriveTokens', $aToken)->set('GoogleDriveAuthParams', $options);
                        $out = [
                            'node' => $options['id'],
                            'json' => '{"protocol": "googledrive", "mode": "done", "reset": 1}',
                            'bind' => 'netmount',
                        ];

                        return ['exit' => 'callback', 'out' => $out];
                    }
                    $folders = [];
                    foreach ($service->files->listFiles([
                        'pageSize' => 1000,
                        'q' => 'trashed = false and mimeType = "application/vnd.google-apps.folder"',
                    ]) as $f) {
                        $folders[$f->getId()] = $f->getName();
                    }
                    natcasesort($folders);
                    $folders         = ['root' => $rootObj->getName()] + $folders;
                    $folders         = json_encode($folders);
                    $json            = '{"protocol": "googledrive", "mode": "done", "folders": '.$folders.'}';
                    $options['pass'] = 'return';
                    $html            = 'Google.com';
                    $html .= '<script>
                        $("#'.$options['id'].'").elfinder("instance").trigger("netmount", '.$json.');
                    </script>';
                    $this->session->set('GoogleDriveAuthParams', $options);

                    return ['exit' => TRUE, 'body' => $html];
                
            }
        } catch (Exception $e) {
            $this->session->remove('GoogleDriveAuthParams')->remove('GoogleDriveTokens');
            if (empty($options['pass'])) {
                return ['exit' => TRUE, 'body' => '{msg:'.elFinder::ERROR_ACCESS_DENIED.'}'.' '.$e->getMessage()];
            }  
                return ['exit' => TRUE, 'error' => [elFinder::ERROR_ACCESS_DENIED, $e->getMessage()]];
            
        }

        if (!$aToken) {
            return ['exit' => TRUE, 'error' => elFinder::ERROR_REAUTH_REQUIRE];
        }

        if ($options['path'] === '/') {
            $options['path'] = 'root';
        }

        try {
            $file             = $service->files->get($options['path']);
            $options['alias'] = sprintf($this->options['gdAlias'], $file->getName());
        } catch (Google_Service_Exception $e) {
            $err = @json_decode($e->getMessage(), TRUE);
            if (isset($err['error']) && $err['error']['code'] == 404) {
                return ['exit' => TRUE, 'error' => [elFinder::ERROR_TRGDIR_NOT_FOUND, $options['path']]];
            }  
                return ['exit' => TRUE, 'error' => $e->getMessage()];
            
        } catch (Exception $e) {
            return ['exit' => TRUE, 'error' => $e->getMessage()];
        }

        foreach (['host', 'user', 'pass', 'id', 'offline'] as $key) {
            unset($options[$key]);
        }

        return $options;
    }

    /**
     * process of on netunmount
     * Drop table `dropbox` & rm thumbs.
     *
     * @param array $options
     *
     * @return bool
     */
    public function netunmount($netVolumes, $key)
    {
        $cache = $this->options['gdCacheDir'].DIRECTORY_SEPARATOR.$this->options['gdCachePrefix'].$this->netMountKey;
        if (file_exists($cache) && is_writable($cache)) {
            unlink($cache);
        }
        if ($tmbs = glob($this->tmbPath.DIRECTORY_SEPARATOR.$this->netMountKey.'*')) {
            foreach ($tmbs as $file) {
                unlink($file);
            }
        }

        return TRUE;
    }

    /**
     * "Mount" volume.
     * Return true if volume available for read or write,
     * false - otherwise.
     *
     * @return bool
     *
     * @author Naoki Sawada
     **/
    public function mount(array $opts)
    {
        $creds = NULL;
        if (isset($opts['access_token'])) {
            $this->netMountKey = md5(implode('-', ['googledrive', $opts['path'], (isset($opts['access_token']['refresh_token']) ? $opts['access_token']['refresh_token'] : $opts['access_token']['access_token'])]));
        }

        $client = new \Google_Client();
        $client->setClientId($opts['client_id']);
        $client->setClientSecret($opts['client_secret']);

        if (!empty($opts['access_token'])) {
            $client->setAccessToken($opts['access_token']);
        }
        if ($client->isAccessTokenExpired()) {
            try {
                $creds = $client->fetchAccessTokenWithRefreshToken();
            } catch (LogicException $e) {
                $this->session->remove('GoogleDriveAuthParams');
                throw $e;
            }
        }

        $service = new \Google_Service_Drive($client);

        // If path is not set, use the root
        if (!isset($opts['path']) || $opts['path'] === '') {
            $opts['path'] = 'root';
        }

        $googleDrive = new GoogleDriveAdapter($service, $opts['path'], ['useHasDir' => TRUE]);

        $opts['fscache'] = NULL;
        if ($this->options['gdCacheDir'] && is_writable($this->options['gdCacheDir'])) {
            if ($this->options['gdCacheExpire']) {
                $opts['fscache'] = new elFinderVolumeFlysystemGoogleDriveCache(new Local($this->options['gdCacheDir']), $this->options['gdCachePrefix'].$this->netMountKey, $this->options['gdCacheExpire']);
            }
        }
        if ($opts['fscache']) {
            $filesystem = new Filesystem(new CachedAdapter($googleDrive, $opts['fscache']));
        } else {
            $filesystem = new Filesystem($googleDrive);
        }

        $opts['driver']          = 'Flysystem'.(class_exists('elFinderVolumeFlysystemExt') ? 'Ext' : '');
        $opts['filesystem']      = $filesystem;
        $opts['checkSubfolders'] = TRUE;
        if (!isset($opts['alias'])) {
            $opts['alias'] = 'GoogleDrive';
        }

        if ($res = parent::mount($opts)) {
            // update access_token of session data
            if ($creds) {
                $netVolumes                                     = $this->session->get('netvolume');
                $netVolumes[$this->netMountKey]['access_token'] = array_merge($netVolumes[$this->netMountKey]['access_token'], $creds);
                $this->session->set('netvolume', $netVolumes);
            }
        }

        return $res;
    }

    /**
     * Get script url.
     *
     * @return string full URL
     *
     * @author Naoki Sawada
     */
    private function getConnectorUrl()
    {
        $url = ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https://' : 'http://')
               .$_SERVER['SERVER_NAME']                                              // host
              .($_SERVER['SERVER_PORT'] == 80 ? '' : ':'.$_SERVER['SERVER_PORT'])  // port
               .$_SERVER['REQUEST_URI'];                                             // path & query
        list($url) = explode('?', $url);

        return $url;
    }

    /**
     * {@inheritdoc}
     */
    protected function tmbname($stat)
    {
        return $this->netMountKey.substr(substr($stat['hash'], strlen($this->id)), -38).$stat['ts'].'.png';
    }
}