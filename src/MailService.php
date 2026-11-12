<?php
declare(strict_types=1);
namespace VenVia;
use RuntimeException;

/** Single outbound channel; deliberately no OS shell or external SMTP API. */
final class MailService {
    private static function key(): string {
        $key=base64_decode((string)(Core::config()['app_key']??''),true);
        if(!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Chiave applicativa non disponibile');
        return $key;
    }
    private static function path():string{return Core::stateDir().'/mail-settings.enc';}
    private static function validAddress(string $v):bool {return strlen($v)<=254 && filter_var($v,FILTER_VALIDATE_EMAIL)!==false && !preg_match('/[\r\n<>]/',$v);}
    public static function configured():bool {return is_file(self::path());}
    public static function load():array {
        if(!self::configured())throw new RuntimeException('SMTP non configurato');
        $cipher=@file_get_contents(self::path());
        if($cipher===false)throw new RuntimeException('SMTP non leggibile');
        $raw=Core::unseal(trim($cipher),self::key());
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Impostazioni SMTP non valide');
        return $data;
    }
    public static function publicSettings():array {
        if(!self::configured())return [];
        $s=self::load();unset($s['password']);$s['has_password']=true;return $s;
    }
    public static function save(array $v):void {
        $previous=self::configured()?self::load():[];
        $host=trim((string)($v['host']??''));
        if(!preg_match('/^(?=.{1,253}$)[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D',$host))throw new RuntimeException('Host SMTP non valido');
        $port=filter_var($v['port']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        $mode=(string)($v['mode']??'');
        if($port===false||!in_array($mode,['starttls','tls'],true))throw new RuntimeException('Porta o crittografia non valide');
        $username=trim((string)($v['username']??''));
        $password=(string)($v['password']??'');
        if($password==='')$password=(string)($previous['password']??'');
        $from=trim((string)($v['from']??''));
        $name=trim((string)($v['name']??'VenVia'));
        if(!self::validAddress($from)||strlen($username)>254||$username===''||$password===''||strlen($password)>4096||strlen($name)>120||preg_match('/[\r\n]/',$username.$name))throw new RuntimeException('Parametri SMTP non validi');
        $data=['host'=>$host,'port'=>$port,'mode'=>$mode,'username'=>$username,'password'=>$password,'from'=>$from,'name'=>$name];
        $path=self::path();$dir=dirname($path);
        if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Directory tecnica non scrivibile');
        $tmp=$dir.'/.smtp-'.bin2hex(random_bytes(12)).'.tmp';
        $f=@fopen($tmp,'x');if($f===false)throw new RuntimeException('Impossibile salvare SMTP');
        try {
            @chmod($tmp,0600);
            $sealed=Core::seal(json_encode($data,JSON_THROW_ON_ERROR),self::key());
            if(fwrite($f,$sealed)!==strlen($sealed))throw new RuntimeException('Scrittura SMTP incompleta');
            fflush($f);
        } finally {fclose($f);}
        if(!@rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Salvataggio SMTP non riuscito');}
        @chmod($path,0600);
    }
    private static function line($fp):array {
        $parts=[];$n=0;
        do {
            $s=fgets($fp,4096);
            if($s===false||++$n>30)throw new RuntimeException('Risposta server SMTP non valida');
            $parts[]=rtrim($s,"\r\n");
        }while(strlen($s)>=4&&$s[3]==='-');
        $last=end($parts);if(!preg_match('/^[0-9]{3}[ -]/',$last))throw new RuntimeException('Risposta SMTP non riconosciuta');
        return [(int)substr($last,0,3),implode("\n",$parts)];
    }
    private static function expect($fp,array $expected):string {
        [$code,$msg]=self::line($fp);
        if(!in_array($code,$expected,true))throw new RuntimeException('SMTP ha rifiutato il comando (codice '.$code.')');
        return $msg;
    }
    private static function command($fp,string $s,array $expected):string {
        if(fwrite($fp,$s."\r\n")===false)throw new RuntimeException('Connessione SMTP interrotta');
        return self::expect($fp,$expected);
    }
    public static function send(string $to,string $subject,string $body):void {
        if(!self::validAddress($to)||preg_match('/[\r\n]/',$subject)||strlen($subject)>160)throw new RuntimeException('Destinatario o oggetto non validi');
        $s=self::load();
        $target=($s['mode']==='tls'?'ssl':'tcp').'://'.$s['host'].':'.$s['port'];
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'SNI_enabled'=>true,'peer_name'=>$s['host'],'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $errno=0;$errstr='';$fp=@stream_socket_client($target,$errno,$errstr,12,STREAM_CLIENT_CONNECT,$context);
        if(!is_resource($fp))throw new RuntimeException('Connessione SMTP non riuscita');
        stream_set_timeout($fp,12);
        try {
            self::expect($fp,[220]);
            $cap=self::command($fp,'EHLO venvia.local',[250]);
            if($s['mode']==='starttls') {
                if(stripos($cap,'STARTTLS')===false)throw new RuntimeException('Il server SMTP non supporta STARTTLS');
                self::command($fp,'STARTTLS',[220]);
                if(stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)!==true)throw new RuntimeException('Negoziazione TLS non riuscita');
                $cap=self::command($fp,'EHLO venvia.local',[250]);
            }
            if(stripos($cap,'AUTH')===false)throw new RuntimeException('Autenticazione SMTP non disponibile');
            self::command($fp,'AUTH LOGIN',[334]);
            self::command($fp,base64_encode($s['username']),[334]);
            self::command($fp,base64_encode($s['password']),[235]);
            self::command($fp,'MAIL FROM:<'.$s['from'].'>',[250]);
            self::command($fp,'RCPT TO:<'.$to.'>',[250,251]);
            self::command($fp,'DATA',[354]);
            $cleanName=preg_replace('/[^\x20-\x7E]/','',(string)$s['name']);
            $safeSubject='=?UTF-8?B?'.base64_encode($subject).'?=';
            $headers='From: '.($cleanName!==''?$cleanName.' ':'').'<'.$s['from'].">\r\n".'To: <'.$to.">\r\n".'Subject: '.$safeSubject."\r\n".'Date: '.gmdate('D, d M Y H:i:s').' +0000'."\r\n".'MIME-Version: 1.0'."\r\n".'Content-Type: text/plain; charset=UTF-8'."\r\n".'Content-Transfer-Encoding: base64'."\r\n";
            $payload=$headers."\r\n".chunk_split(base64_encode($body),76,"\r\n");
            if(strlen($payload)>200000)throw new RuntimeException('Messaggio troppo grande');
            if(fwrite($fp,$payload."\r\n.\r\n")===false)throw new RuntimeException('Invio SMTP interrotto');
            self::expect($fp,[250]);
            @fwrite($fp,"QUIT\r\n");
        }finally{fclose($fp);}
    }
}
