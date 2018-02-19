<?php

/*Auteur : warkx
  Partie premium developpé par : Einsteinium
  Aidé par : Polo.Q, Samzor
  Version : 1.6
  Développé le : 17/02/2018
  Description : Support du compte gratuit et premium*/

class SynoFileHosting
{
    private $Url;
    private $Username;
    private $Password;
    private $HostInfo;
    private $CookieValue;
  
    private $COOKIE_FILE = '/tmp/uptobox.cookie';
    private $LOGIN_URL = 'https://login.uptobox.com/logarithme';
    private $ACCOUNT_TYPE_URL = 'https://uptobox.com/?op=my_account';
  
    private $FILE_NAME_REGEX = '/<title>(.*)<\/title>/si';
    private $FILE_OFFLINE_REGEX = '/The file was deleted|Page not found/i';
    private $DOWNLOAD_WAIT_REGEX = '/can wait (.+) to launch a new download/i';
    private $FILE_URL_REGEX = '/href=\"(https?:\/\/\w+\.uptobox\.com\/dl\/.*)\"/i';
    private $ACCOUNT_TYPE_REGEX = '/Premium\s*member/i';
    private $ERROR_404_URL_REGEX = '/uptobox.com\/404.html/i';
    private $WAITINGTOKEN_REGEX = '/\s+name=\'waitingToken\'\s+value=\'([^\']+)\'/i';
  
    private $STRING_COUNT = 'count';
    private $STRING_FNAME = 'fname';
    private $QUERYAGAIN = 1;
    private $WAITING_TIME_DEFAULT = 1800;
    
    private $TAB_REQUEST = array('fname' => '');
  
    public function __construct($Url, $Username, $Password, $HostInfo) 
    {
		$this->Url = $Url;
		$this->Username = $Username;
		$this->Password = $Password;
		$this->HostInfo = $HostInfo;
		$this->logger("[__construct] Url: ${Url}");
		$this->logger("[__construct] Username: ${Username}");
		$this->logger("[__construct] Password: ${Password}");
		$this->logger("[__construct] HostInfo: ".print_r($HostInfo,true));
	}
  
    //se connecte et renvoie le type du compte
    public function Verify($ClearCookie)
    {
		$this->logger("[Verify]");
	
        $ret = LOGIN_FAIL;
        $this->CookieValue = false;
  
        //si le nom d'utilisateur et le mot de passe sont entré on se connecte
        //renvoie le cookie si la connexion est initialisé
        if(!empty($this->Username) && !empty($this->Password)) 
        {
            $this->CookieValue = $this->Login($this->Username, $this->Password);
        }
        //Verifie le type de compte
        if($this->CookieValue != false) 
        {
            $ret = $this->AccountType($this->Username, $this->Password);
        }
        if ($ClearCookie && file_exists($this->COOKIE_FILE)) 
        {
            unlink($this->COOKIE_FILE);
        }
		$this->logger("[Verify] ret: ${ret}");
        return $ret;
    }
    
    //Lance le telechargement en fonction du type de compte
    public function GetDownloadInfo()
    {
		$this->logger("[GetDownloadInfo]");
        $ret = false;
        $VerifyRet = $this->Verify(false);
		
		$this->logger("[GetDownloadInfo] VerifyRet : ${VerifyRet}");
    
        if(USER_IS_PREMIUM == $VerifyRet)
        {
            $ret = $this->DownloadPremium();
      
        }else if(USER_IS_FREE == $VerifyRet)
        {
            $ret = $this->DownloadWaiting(true);
        }else
        {
            $ret = $this->DownloadWaiting(false);
        }
    
        if($ret != false)
        {
            $ret[INFO_NAME] = trim($this->HostInfo[INFO_NAME]);
        }
		$this->logger("[GetDownloadInfo] ret : ".print_r($ret,true));
    
        return $ret;
    }
  
    //Telechargement en mode premium
    private function DownloadPremium()
    {
		$this->logger("[DownloadPremium]");
		
        $ret = false;
        $DownloadInfo = array();
        $ret = $this->UrlFilePremium();
        if($ret == false)
        {
            $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
        }else
        {
			preg_match($this->FILE_NAME_REGEX, $page, $filenamematch);
			if(!empty($filenamematch[1]))
			{
				$this->TAB_REQUEST[$this->STRING_FNAME] = $filenamematch[1];
			}
		  
			$page = $this->UrlFileFreeUrlFileFree(true);
			preg_match($this->FILE_URL_REGEX,$page,$urlmatch);
			if(!empty($urlmatch[1])) {
				$DownloadInfo[DOWNLOAD_URL] = $urlmatch[1];
			} else {
				$DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
			}
			$DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = true;
			$DownloadInfo[DOWNLOAD_FILENAME] = $this->TAB_REQUEST[$this->STRING_FNAME];
			$DownloadInfo[DOWNLOAD_COOKIE] = $this->COOKIE_FILE;
        }
		$this->logger("[DownloadPremium] DownloadInfo : {$DownloadInfo[DOWNLOAD_URL]}");
		
        return $DownloadInfo;
    }
    
    //telechargement en mode gratuit ou sans compte
    private function DownloadWaiting($LoadCookie)
    {
		$this->logger("[DownloadWaiting] LoadCookie : ${LoadCookie}");
        $DownloadInfo = false;
        $page = $this->DownloadParsePage($LoadCookie);

        if($page != false)
        {
            //Termine la fonction si le fichier est offline
            preg_match($this->FILE_OFFLINE_REGEX,$page,$errormatch);
            if(isset($errormatch[0]))
            {
                $DownloadInfo[DOWNLOAD_ERROR] = ERR_FILE_NO_EXIST;
            }else
            {
				preg_match($this->FILE_NAME_REGEX, $page, $filenamematch);
				if(!empty($filenamematch[1]))
				{
					$this->TAB_REQUEST[$this->STRING_FNAME] = $filenamematch[1];
				}
				$DownloadInfo[DOWNLOAD_FILENAME] = $this->TAB_REQUEST[$this->STRING_FNAME];
					
                //verifie s'il faut attendre et si c'est le cas, renvoie le temps d'attente
                $result = $this->VerifyWaitDownload($page);
                if($result != false)
                {
                    $DownloadInfo[DOWNLOAD_COUNT] = (int) $result[$this->STRING_COUNT];
                    $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = (int) $this->QUERYAGAIN;
                }else
                {
                    
                    //clique sur le bouton "Generer le lien" et recupere la vrai URL
                    $page = $this->UrlFileFree($LoadCookie);
                    preg_match($this->FILE_URL_REGEX,$page,$urlmatch);
                    if(!empty($urlmatch[1]))
                    {
                        $DownloadInfo[DOWNLOAD_URL] = $urlmatch[1];
                    }else
                    {
                        $DownloadInfo[DOWNLOAD_COUNT] = (int) $this->WAITING_TIME_DEFAULT;
                        $DownloadInfo[DOWNLOAD_ISQUERYAGAIN] = (int) $this->QUERYAGAIN;
                    }
                }
                $DownloadInfo[DOWNLOAD_ISPARALLELDOWNLOAD] = true;
            }
            if($LoadCookie == true)
            {
                $DownloadInfo[DOWNLOAD_COOKIE] = $this->COOKIE_FILE;
            }
        }
		$this->logger("[DownloadWaiting] DownloadInfo : ".print_r($DownloadInfo,true));
		
        return $DownloadInfo;
    }
    
    //Renvoie le temps d'attente indiqué sur la page, ou false s'il n'y en a pas
    private function VerifyWaitDownload($page)
    {
		$this->logger("[VerifyWaitDownload]");
        $ret = false;
        
		preg_match($this->DOWNLOAD_WAIT_REGEX, $page, $waitingmatch);
        if(!empty($waitingmatch[0]))
        {
            if(!empty($waitingmatch[1]))
            {
                $waitingtime = 0;
                preg_match('`(\d+) hour`si', $waitingmatch[1], $waitinghourmatch);
                if(!empty($waitinghourmatch[1]))
                {
                    $waitingtime = ($waitinghourmatch[1] * 3600);
                }
                preg_match('`(\d+) minute`si', $waitingmatch[1], $waitingminmatch);
                if(!empty($waitingminmatch[1]))
                {
                    $waitingtime = $waitingtime + ($waitingminmatch[1] * 60) + 70;
                }
            }else
            {
                $waitingtime = 70;
            }
            $ret[$this->STRING_COUNT] = $waitingtime;
        }
		
		preg_match_all($this->WAITINGTOKEN_REGEX, $page, $waitingtokenmatch);
        if(!empty($waitingtokenmatch[0])){
			// attente des 30 secondes
			$this->logger("[VerifyWaitDownload] sleep");
			$this->logger("[VerifyWaitDownload] waitingtokenmatch : {$waitingtokenmatch[1][0]} ");
			$this->TAB_REQUEST['waitingToken'] = $waitingtokenmatch[1][0];
            $ret = false;
			sleep(35);
		}
		
		$this->logger("[VerifyWaitDownload] ret : ".print_r($ret,true));
        return $ret;
    }
  
    //authentifie l'utilisateur sur le site
    private function Login()
    {
		$this->logger("[Login]");
        $ret = LOGIN_FAIL;
		$PostData = array('login'=>$this->Username,
                        'password'=>$this->Password,
                        'op'=>'login');
            
		$queryUrl = $this->LOGIN_URL;
		$PostData = http_build_query($PostData);
		$curl = curl_init();
		$header[] = "Accept-Language: en";
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header); 
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_POSTFIELDS, $PostData);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_COOKIEJAR, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_HEADER, TRUE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_URL, $queryUrl);
		$LoginInfo = curl_exec($curl);
		curl_close($curl);
		
		if ($LoginInfo != false && file_exists($this->COOKIE_FILE)) 
        {
			$cookieData = file_get_contents ($this->COOKIE_FILE);
			if(strpos($cookieData,'xfss') == true) 
            {
				$ret = true;
			}else 
            {
                $ret = false;
            }
		}
		$this->logger("[Login] ret : ${ret}");
		return $ret;
    }
  
    //renvoie premium si le compte est premium sinon concidere qu'il est gratuit
    private function AccountType()
    {
		$this->logger("[AccountType]");
        $ret = false;
        $curl = curl_init();
		$header[] = "Accept-Language: en";
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header); 
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_HEADER, TRUE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_URL, $this->ACCOUNT_TYPE_URL);
		$page = curl_exec($curl);
		curl_close($curl);
    
        preg_match($this->ACCOUNT_TYPE_REGEX,$page,$accouttypematch);
        if(isset($accouttypematch[0]))
        {
            $ret = USER_IS_PREMIUM;
        }else
        {
            $ret = USER_IS_FREE;
        }
		$this->logger("[AccountType] ret : ${ret}");
        return $ret;
    }
    
    //affiche la page en mode gratuit
    private function DownloadParsePage($LoadCookie)
    {
		$this->logger("[DownloadParsePage]");
		$this->logger("[DownloadParsePage] LoadCookie : ${LoadCookie}");
        $ret = false;
        $curl = curl_init(); 
		$header[] = "Accept-Language: en";
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header); 
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_USERAGENT,DOWNLOAD_STATION_USER_AGENT);
        if($LoadCookie == true)
        {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
        }
        curl_setopt($curl, CURLOPT_HEADER, TRUE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE); 
        curl_setopt($curl, CURLOPT_URL, $this->Url); 
    
        $ret = curl_exec($curl); 
        $info = curl_getinfo($curl);
        curl_close($curl);
    
        $this->Url = $info['url'];
		$this->logger("[DownloadParsePage] ret : ${ret}");
        return $ret; 
    }
  
    //renvoie la vrai URL du fichier en mode gratuit
    private function UrlFileFree($LoadCookie)
    {
		$this->logger("[UrlFileFree] LoadCookie : ${LoadCookie}");
        $ret = false;
        $data = $this->TAB_REQUEST;
        $data = http_build_query($data);
		$curl = curl_init();
		$header[] = "Accept-Language: en";
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE); 
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_USERAGENT,DOWNLOAD_STATION_USER_AGENT);
        if($LoadCookie == true)
        {
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
        }
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_URL, $this->Url);
    
        $header = curl_exec($curl);
        curl_close($curl);
        
        $ret = $header;
		$this->logger("[UrlFileFree] ret : ${ret}");
        return $ret;
    }
  
    //renvoie la vrai url du fichier en mode premium. Ou false si elle n'est pa affiché
    private function UrlFilePremium()
    {
		$this->logger("[UrlFilePremium]");
        $ret = false;
		$curl = curl_init();
		$header[] = "Accept-Language: en";
		curl_setopt($curl, CURLOPT_HTTPHEADER, $header); 
		curl_setopt($curl, CURLOPT_USERAGENT, DOWNLOAD_STATION_USER_AGENT);
		curl_setopt($curl, CURLOPT_URL, $this->Url);
		curl_setopt($curl, CURLOPT_COOKIEFILE, $this->COOKIE_FILE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); 
		curl_setopt($curl, CURLOPT_HEADER, true);

		$header = curl_exec($curl);
		$info = curl_getinfo($curl);
        curl_close($curl);
    
		$error_code = $info['http_code'];
		
		if ($error_code == 301 || $error_code == 302) 
        { 
			$ret = $info['redirect_url'];
		}
        preg_match($this->ERROR_404_URL_REGEX, $ret, $finderror);
        if(isset($finderror[0]))
        {
            $ret = false;
        }else
        {
            $ret = $header;
        }
		$this->logger("[UrlFilePremium] ret : ${ret}");
		return $ret;
    }
	
	// pour debug
	private function logger($texte) {
		file_put_contents("/tmp/logs.txt", $texte.PHP_EOL , FILE_APPEND);
	}
}
?>
