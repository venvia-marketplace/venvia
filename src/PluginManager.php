<?php
declare(strict_types=1);
namespace VenVia;

use PDO;
use RuntimeException;
use ZipArchive;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/** VenVia DEV plugin lifecycle. Only developer-trusted ZIPs; PHP plugins execute with web privileges. */
final class PluginManager {
    public const LIMIT_BYTES = 20971520;
    public static function home(): string { return Core::root().'/plugins'; }
    public static function uploadDir(): string { return Core::stateDir().'/plugin-upload'; }
    public static function identifier(string $id): bool { return (bool)preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $id) && strlen($id)<=100; }
    public static function sdkVersion(): string { return '1.1'; }
    public static function isWritable(): bool { return is_dir(self::home()) && is_writable(self::home()); }
    private static function ensureUploadDir(): void {
        if (!is_dir(self::uploadDir()) && !mkdir(self::uploadDir(),0700,true) && !is_dir(self::uploadDir())) throw new RuntimeException('Area temporanea non disponibile.');
        chmod(self::uploadDir(),0700);
    }
    private static function manifestData(string $json): array {
        $m=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($m)) throw new RuntimeException('Manifesto plugin non valido.');
        $id=$m['id']??null;$version=$m['version']??null;
        if (!is_string($id) || !self::identifier($id)) throw new RuntimeException('ID plugin non valido.');
        if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D',$version) || strlen($version)>40) throw new RuntimeException('Versione SemVer non valida.');
        if (!in_array(($m['sdk']??null),['1.0','1.1'],true)) throw new RuntimeException('Versione SDK incompatibile.');
        if (!is_string($m['name']??null) || strlen($m['name'])>120 || trim($m['name'])==='') throw new RuntimeException('Nome plugin non valido.');
        $deps=$m['dependencies']??[];if (!is_array($deps)||count($deps)>30) throw new RuntimeException('Dipendenze non valide.');
        foreach($deps as $d){if(!is_string($d)||!self::identifier($d)||$d===$id)throw new RuntimeException('Dipendenza non valida.');}
        $min=$m['min_core']??'0.2.0'; if(!is_string($min)||!preg_match('/^\d+\.\d+\.\d+$/D',$min)||version_compare(Core::numericVersion(),$min,'<'))throw new RuntimeException('Versione Core insufficiente.');
        $php=$m['min_php']??'8.3.0';if(!is_string($php)||!preg_match('/^\d+\.\d+\.\d+$/D',$php)||version_compare(PHP_VERSION,$php,'<'))throw new RuntimeException('Versione PHP insufficiente.');
        $routes=$m['routes']??[];if(!is_array($routes))throw new RuntimeException('Rotte non valide.');
        foreach($routes as $side=>$file){if(!in_array($side,['frontend','backend'],true)||!is_string($file)||!preg_match('#^[a-zA-Z0-9_/-]+\.php$#D',$file)||str_contains($file,'..')||str_starts_with($file,'/'))throw new RuntimeException('Rotta non consentita.');}
        $m['dependencies']=array_values($deps);$m['routes']=$routes;
        $m['ui']=InterfaceAPI::validateUi($m);
        return $m;
    }
    /** Validate ZIP paths, compression, symlinks, size, and manifest before ANY extraction. */
    private static function inspectZip(string $file): array {
        if(!class_exists(ZipArchive::class))throw new RuntimeException('Estensione PHP zip non installata.');
        $z=new ZipArchive();if($z->open($file)!==true)throw new RuntimeException('Archivio ZIP non valido.');
        try {
            if($z->numFiles<1 || $z->numFiles>500)throw new RuntimeException('Numero file ZIP non consentito.');
            $size=0;$names=[];
            for($i=0;$i<$z->numFiles;$i++){
                $s=$z->statIndex($i);$name=(string)$s['name'];
                if(strlen($name)>200 || str_contains($name,'\\') || str_starts_with($name,'/') || str_contains($name,'//') || preg_match('#(^|/)\.\.?(/|$)#',$name) || str_contains($name,"\0") || preg_match('/[\x00-\x1f]/',$name))throw new RuntimeException('Percorso ZIP non sicuro.');
                if(isset($names[strtolower($name)]))throw new RuntimeException('Percorsi duplicati nello ZIP.');$names[strtolower($name)]=true;
                if(str_ends_with($name,'/'))continue;
                if(!preg_match('/\.(?:php|css|js|json|md|txt|html|svg|png|jpg|jpeg|gif|ico|webp)$/i',$name))throw new RuntimeException('Tipo file non consentito nello ZIP: '.$name);
                $size+=(int)$s['size'];if($size>self::LIMIT_BYTES)throw new RuntimeException('Plugin troppo grande.');
                if((int)$s['size']>0 && (int)$s['comp_size']>0 && (int)$s['size']>max(100000,(int)$s['comp_size']*100))throw new RuntimeException('Compressione ZIP sospetta.');
                $ops=0;$attrs=0;if($z->getExternalAttributesIndex($i,$ops,$attrs)) { $type=($attrs>>16)&0170000; if($type===0120000)throw new RuntimeException('Link simbolici non ammessi.'); }
            }
            if(!isset($names['plugin.json']))throw new RuntimeException('Manca plugin.json nella radice dello ZIP.');
            $json=$z->getFromName('plugin.json');if(!is_string($json)||strlen($json)>20000)throw new RuntimeException('Manifesto non valido.');
            $m=self::manifestData($json);
            foreach(array_merge(array_values($m['routes']),InterfaceAPI::referencedFiles($m)) as $route)if(!isset($names[strtolower($route)]))throw new RuntimeException('File di rotta o asset mancante: '.$route);
            return $m;
        } finally {$z->close();}
    }
    public static function stage(array $file): array {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??'')))throw new RuntimeException('Caricamento ZIP non riuscito.');
        if((int)$file['size']>self::LIMIT_BYTES || (int)$file['size']<100)throw new RuntimeException('Dimensione archivio non consentita.');
        self::ensureUploadDir();$name=bin2hex(random_bytes(16)).'.zip';$dst=self::uploadDir().'/'.$name;
        if(!move_uploaded_file($file['tmp_name'],$dst))throw new RuntimeException('Impossibile salvare il pacchetto.');
        chmod($dst,0600);
        try {$m=self::inspectZip($dst);}catch(\Throwable $e){@unlink($dst);throw $e;}
        return ['filename'=>$name,'manifest'=>$m,'sha256'=>hash_file('sha256',$dst),'at'=>time()];
    }
    public static function getStaged(array $stage): array {
        if(time()-(int)($stage['at']??0)>900)throw new RuntimeException('Anteprima scaduta. Ricarica il pacchetto.');
        $name=(string)($stage['filename']??'');if(!preg_match('/^[a-f0-9]{32}\.zip$/D',$name))throw new RuntimeException('Pacchetto non valido.');
        $file=self::uploadDir().'/'.$name;
        if(!is_file($file)||!hash_equals((string)$stage['sha256'],hash_file('sha256',$file)))throw new RuntimeException('Pacchetto modificato.');
        $m=self::inspectZip($file);if($m!==$stage['manifest'])throw new RuntimeException('Manifesto modificato.');
        return [$file,$m];
    }
    public static function discard(?array $stage): void {
        if(!$stage)return;$name=$stage['filename']??'';
        if(is_string($name)&&preg_match('/^[a-f0-9]{32}\.zip$/D',$name))@unlink(self::uploadDir().'/'.$name);
    }
    public static function list(PDO $db): array {
        return $db->query('SELECT plugin_id,version,status FROM vv_plugin_registry ORDER BY plugin_id')->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function manifest(string $id): array {
        if(!self::identifier($id))throw new RuntimeException('Plugin non valido.');
        $dir=self::home().'/'.$id;
        if(!is_dir($dir) || is_link($dir) || !is_file($dir.'/plugin.json') || is_link($dir.'/plugin.json'))throw new RuntimeException('File del plugin mancanti: usa SFTP per ripristinare la directory.');
        $m=self::manifestData((string)file_get_contents($dir.'/plugin.json'));
        if($m['id']!==$id)throw new RuntimeException('ID manifesto non coerente.');
        return $m;
    }
    public static function dependenciesOk(PDO $db,array $m): void {
        $q=$db->prepare('SELECT status FROM vv_plugin_registry WHERE plugin_id=?');
        foreach($m['dependencies'] as $id){$q->execute([$id]);if($q->fetchColumn()!=='active')throw new RuntimeException('Dipendenza non attiva: '.$id);}
    }
    public static function install(PDO $db,array $stage): string {
        [$file,$m]=self::getStaged($stage);$id=$m['id'];
        if(!self::isWritable())throw new RuntimeException('Directory plugin non scrivibile: verifica permessi.');
        $q=$db->prepare('SELECT version,status FROM vv_plugin_registry WHERE plugin_id=?');$q->execute([$id]);$existing=$q->fetch(PDO::FETCH_ASSOC);
        if($existing && version_compare($m['version'],$existing['version'],'<='))throw new RuntimeException('Per aggiornare è richiesta una versione superiore.');
        if($existing && $existing['status']==='active')throw new RuntimeException('Disattiva il plugin prima di aggiornarlo.');
        self::dependenciesOk($db,$m);
        $tmp=self::home().'/.install-'.bin2hex(random_bytes(8));$backup=null;$deployed=false;$dst=self::home().'/'.$id;
        if(!$existing && file_exists($dst))throw new RuntimeException('Directory plugin già presente senza registrazione: verifica via SFTP.');
        if(!mkdir($tmp,0750))throw new RuntimeException('Impossibile creare directory temporanea.');
        try {
            $zip=new ZipArchive();if($zip->open($file)!==true)throw new RuntimeException('ZIP non disponibile.');
            try {if(!$zip->extractTo($tmp))throw new RuntimeException('Estrazione fallita.');}finally{$zip->close();}
            // Recheck individual contents after extraction and disallow unexpected symlinks.
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
            foreach($it as $entry){if($entry->isLink())throw new RuntimeException('Link non ammesso.');}
            if($existing){if(!is_dir($dst)||is_link($dst))throw new RuntimeException('Directory plugin esistente incoerente.');$backup=self::home().'/.rollback-'.$id.'-'.bin2hex(random_bytes(5));if(!rename($dst,$backup))throw new RuntimeException('Impossibile preparare rollback.');}
            if(!rename($tmp,$dst))throw new RuntimeException('Installazione non riuscita.');$deployed=true;
            $q=$db->prepare('INSERT INTO vv_plugin_registry(plugin_id,version,status) VALUES (?,?,\'disabled\') ON DUPLICATE KEY UPDATE version=VALUES(version),status=\'disabled\',updated_at=NOW()');
            $q->execute([$id,$m['version']]);
            if($backup!==null)self::removeTree($backup);
            self::discard($stage);
            return $id;
        }catch(\Throwable $e){
            if(is_dir($tmp))self::removeTree($tmp);
            if($backup!==null && is_dir($backup)){
                if(is_dir($dst))self::removeTree($dst);
                @rename($backup,$dst);
            }elseif(!$existing&&$deployed&&is_dir($dst))self::removeTree($dst);
            throw $e;
        }
    }
    public static function change(PDO $db,string $id,string $action): void {
        if(!self::identifier($id))throw new RuntimeException('Identificativo non valido.');
        $q=$db->prepare('SELECT status FROM vv_plugin_registry WHERE plugin_id=?');$q->execute([$id]);$status=$q->fetchColumn();
        if($status===false)throw new RuntimeException('Plugin non registrato.');
        if($action==='activate'){
            $m=self::manifest($id);self::dependenciesOk($db,$m);
            $q=$db->prepare("UPDATE vv_plugin_registry SET status='active',updated_at=NOW() WHERE plugin_id=?");$q->execute([$id]);return;
        }
        if(!in_array($action,['deactivate','remove'],true))throw new RuntimeException('Operazione non valida.');
        $q=$db->prepare("SELECT plugin_id FROM vv_plugin_registry WHERE status='active' AND plugin_id<>?");$q->execute([$id]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $other){try {$m=self::manifest($other);if(in_array($id,$m['dependencies'],true))throw new RuntimeException('Plugin richiesto da: '.$other);}catch(RuntimeException $e){if(str_starts_with($e->getMessage(),'Plugin richiesto'))throw $e;}}
        if($action==='deactivate'){$q=$db->prepare("UPDATE vv_plugin_registry SET status='disabled',updated_at=NOW() WHERE plugin_id=?");$q->execute([$id]);return;}
        if($status==='active')throw new RuntimeException('Disattiva prima di rimuovere.');
        $dst=self::home().'/'.$id;
        if(is_dir($dst)){if(is_link($dst))throw new RuntimeException('Directory link non ammessa.');self::removeTree($dst);}
        $q=$db->prepare('DELETE FROM vv_plugin_registry WHERE plugin_id=?');$q->execute([$id]);
    }
    private static function removeTree(string $path): void {
        $root=realpath(self::home());$real=realpath($path);
        if(!$root||!$real||!str_starts_with($real,$root.'/')||is_link($path))throw new RuntimeException('Percorso eliminazione non sicuro.');
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}
        rmdir($path);
    }
    public static function frontendHtml(PDO $db,string $id,string $side,array $context): string {
        if(!in_array($side,['frontend','backend'],true) || !self::identifier($id))throw new RuntimeException('Modulo non valido.');
        $q=$db->prepare("SELECT status FROM vv_plugin_registry WHERE plugin_id=?");$q->execute([$id]);
        if($q->fetchColumn()!=='active')throw new RuntimeException('Modulo non attivo.');
        $m=self::manifest($id);$f=$m['routes'][$side]??null;if(!$f)throw new RuntimeException('Interfaccia non disponibile nel modulo.');
        $full=self::home().'/'.$id.'/'.$f;
        if(!is_file($full)||is_link($full))throw new RuntimeException('Interfaccia del modulo mancante.');
        $value=(static function (string $file,array $context) {return require $file;})($full,$context);
        if(!is_string($value))throw new RuntimeException('Il modulo deve restituire HTML come stringa.');
        return $value;
    }
}
