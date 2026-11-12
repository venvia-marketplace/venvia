<?php
declare(strict_types=1);
namespace VenVia;

use PDO;
use RuntimeException;

/** SDK 1.1 read-only interface registry. Authentication and ACL remain in the Core. */
final class InterfaceAPI {
    public const SDK = '1.1';
    public static function context(array $user, string $area): array {
        if (!in_array($area, ['frontend','backend'], true)) throw new RuntimeException('Area non valida');
        $role=(string)($user['role']??'');
        $caps=['frontend.access'];
        if($role==='administrator') $caps[]='backend.access';
        return [
            'user_id'=>(int)($user['id']??0), 'username'=>(string)($user['username']??''),
            'role'=>$role, 'capabilities'=>$caps, 'area'=>$area,
            'tenant_id'=>null, 'store_id'=>null, // Future tenant/store API; never trust GET/POST for scope.
            'locale'=>'it-IT', 'theme'=>'system', 'csrf'=>Core::csrf(),
        ];
    }
    public static function can(array $ctx, string $capability): bool {
        return $capability!=='' && in_array($capability, $ctx['capabilities']??[], true);
    }
    private static function safeFile(string $file, string $type): bool {
        return (bool)preg_match('#^[A-Za-z0-9_/-]+\.'.preg_quote($type,'#').'$#D',$file)
            && !str_contains($file,'..') && !str_starts_with($file,'/');
    }
    /** Normalize SDK 1.0 + 1.1 manifests. Fail closed on unsupported capability/paths. */
    public static function validateUi(array $m): array {
        $ui=$m['ui']??[];if(!is_array($ui))throw new RuntimeException('ui deve essere un oggetto');
        if(($m['sdk']??'1.0')==='1.0' && $ui!==[])throw new RuntimeException('ui richiede SDK 1.1');
        $pages=$ui['pages']??[];$nav=$ui['navigation']??[];$widgets=$ui['widgets']??[];
        foreach(['pages'=>$pages,'navigation'=>$nav,'widgets'=>$widgets] as $key=>$entries) {
            if(!is_array($entries)||count($entries)>50||!array_is_list($entries))throw new RuntimeException('Sezione ui non valida: '.$key);
        }
        $seen=[];
        foreach($pages as $page) {
            if(!is_array($page))throw new RuntimeException('Pagina non valida');
            $id=$page['id']??'';$side=$page['area']??'';$file=$page['file']??'';
            if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$id)||isset($seen[$id]))throw new RuntimeException('ID pagina duplicato o non valido');
            $seen[$id]=true;
            if(!in_array($side,['frontend','backend'],true)||!is_string($file)||!self::safeFile($file,'php'))throw new RuntimeException('Rotta pagina non valida');
            if(!is_string($page['title']??null)||trim($page['title'])===''||strlen($page['title'])>100)throw new RuntimeException('Titolo pagina non valido');
            if(isset($page['capability'])&&(!is_string($page['capability'])||!in_array($page['capability'],['frontend.access','backend.access'],true))) throw new RuntimeException('Capability non supportata');
        }
        foreach($nav as $item) {
            if(!is_array($item)||!isset($seen[$item['page']??'']))throw new RuntimeException('Menu deve riferirsi a una pagina dichiarata');
            if(!is_string($item['label']??null)||trim($item['label'])===''||strlen($item['label'])>80)throw new RuntimeException('Etichetta menu non valida');
            if(isset($item['order'])&&(!is_int($item['order'])||abs($item['order'])>10000))throw new RuntimeException('Ordine menu non valido');
            if(isset($item['icon'])&&(!is_string($item['icon'])||!preg_match('/^[a-z0-9-]{1,48}$/D',$item['icon'])))throw new RuntimeException('Icona menu non valida');
        }
        $widgetSeen=[];
        foreach($widgets as $widget) {
            if(!is_array($widget)||!is_string($widget['id']??null)||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$widget['id']))throw new RuntimeException('ID widget non valido');
            if(isset($widgetSeen[$widget['id']])) throw new RuntimeException('ID widget duplicato');
            $widgetSeen[$widget['id']]=true;
            if(!in_array($widget['area']??'', ['frontend','backend'],true)||!is_string($widget['file']??null)||!self::safeFile($widget['file'],'php')) throw new RuntimeException('Widget non valido');
            if(isset($widget['capability'])&&!in_array($widget['capability'],['frontend.access','backend.access'],true)) throw new RuntimeException('Capability widget non supportata');
        }
        $home=$ui['home']??[];if(!is_array($home))throw new RuntimeException('Home non valida');
        foreach($home as $area=>$id) {
            if(!in_array($area,['frontend','backend'],true)||!is_string($id)||!isset($seen[$id])) throw new RuntimeException('Home non valida');
            $matching=array_values(array_filter($pages,static fn($p)=>$p['id']===$id && $p['area']===$area));
            if(!$matching)throw new RuntimeException('Home deve riferirsi a una pagina nella stessa area');
        }
        $shell=$ui['shell']??null;if($shell!==null&&(!is_string($shell)||!self::safeFile($shell,'php')))throw new RuntimeException('Shell non valida');
        $assets=$ui['assets']??[];if(!is_array($assets))throw new RuntimeException('Asset non validi');
        foreach($assets as $kind=>$files) {
            if(!in_array($kind,['css','js'],true)||!is_array($files)||count($files)>20)throw new RuntimeException('Asset non validi');
            foreach($files as $f)if(!is_string($f)||!self::safeFile($f,$kind))throw new RuntimeException('Percorso asset non consentito');
        }
        return $ui;
    }
    public static function referencedFiles(array $m): array {
        $ui=self::validateUi($m);$files=[];
        foreach(($ui['pages']??[]) as $p)$files[]=$p['file'];
        foreach(($ui['widgets']??[]) as $w)$files[]=$w['file'];
        if(isset($ui['shell']))$files[]=$ui['shell'];
        foreach(($ui['assets']??[]) as $a)foreach($a as $f)$files[]=$f;
        return array_values(array_unique($files));
    }
    private static function enabled(PDO $db): array {
        $plugins=[];
        foreach(PluginManager::list($db) as $r) {
            if($r['status']!=='active')continue;
            try {$plugins[$r['plugin_id']]=PluginManager::manifest($r['plugin_id']);}
            catch(\Throwable $e){error_log('VenVia plugin manifest unavailable: '.$r['plugin_id']);}
        }
        return $plugins;
    }
    public static function navigation(PDO $db, array $ctx): array {
        $links=[];
        foreach(self::enabled($db) as $id=>$m) {
            foreach(($m['ui']['navigation']??[]) as $n) {
                foreach($m['ui']['pages'] as $p)if($p['id']===$n['page']&&$p['area']===$ctx['area']) {
                    $cap=$p['capability']??($p['area']==='backend'?'backend.access':'frontend.access');
                    if(!self::can($ctx,$cap))continue;
                    $links[]=['id'=>$id.':'.$p['id'],'label'=>$n['label'],'url'=>($ctx['area']==='backend'?'/backend':'/').'?module='.rawurlencode($id).'&view='.rawurlencode($p['id']),'order'=>$n['order']??100,'icon'=>$n['icon']??''];
                }
            }
        }
        usort($links,static fn($a,$b)=>($a['order']<=>$b['order'])?:strcmp($a['id'],$b['id']));
        return $links;
    }
    public static function assetUrl(string $id,string $file): string {
        return '/?plugin_asset='.rawurlencode($id).'&file='.rawurlencode($file);
    }
    public static function assetTags(PDO $db): array {
        $css=[];$js=[];
        foreach(self::enabled($db) as $id=>$m)foreach(($m['ui']['assets']??[]) as $type=>$files)
            foreach($files as $f){$url=Core::e(self::assetUrl($id,$f));if($type==='css')$css[]='<link rel="stylesheet" href="'.$url.'">';else $js[]='<script src="'.$url.'" defer></script>';}
        return [implode('',$css),implode('',$js)];
    }
    private static function invoke(string $id,string $file,array $ctx): string {
        $root=realpath(PluginManager::home().'/'.$id);
        $target=$root?realpath($root.'/'.$file):false;
        if(!$root||!$target||!str_starts_with($target,$root.'/')||!is_file($target)||is_link($target))throw new RuntimeException('File modulo mancante');
        $html=(static function(string $file,array $context){return require $file;})($target,$ctx);
        if(!is_string($html))throw new RuntimeException('La pagina deve restituire una stringa HTML');
        return $html;
    }
    public static function page(PDO $db,string $id,string $view,array $ctx): string {
        if(!PluginManager::identifier($id))throw new RuntimeException('Identificativo modulo non valido');
        $plugins=self::enabled($db);$m=$plugins[$id]??null;
        if(!$m)throw new RuntimeException('Modulo non attivo o non disponibile');
        if(($m['sdk']??'1.0')==='1.0') {
            if($view!=='' && $view!=='default')throw new RuntimeException('Pagina non disponibile');
            $cap=$ctx['area']==='backend'?'backend.access':'frontend.access';
            if(!self::can($ctx,$cap))throw new RuntimeException('Accesso non consentito');
            return self::invoke($id,$m['routes'][$ctx['area']]??throw new RuntimeException('Rotta non disponibile'),$ctx);
        }
        $pages=$m['ui']['pages']??[];
        if($view==='')$view=(string)($m['ui']['home'][$ctx['area']]??'');
        foreach($pages as $p)if($p['id']===$view && $p['area']===$ctx['area']) {
            $cap=$p['capability']??($ctx['area']==='backend'?'backend.access':'frontend.access');
            if(!self::can($ctx,$cap))throw new RuntimeException('Accesso non consentito');
            return self::invoke($id,$p['file'],$ctx);
        }
        throw new RuntimeException('Pagina non disponibile');
    }
    public static function home(PDO $db,array $ctx): ?string {
        $m=self::enabled($db)['venvia-interface']??null;
        $route=$m['ui']['home'][$ctx['area']]??null;
        if(!is_string($route))return null;
        try{return self::page($db,'venvia-interface',$route,$ctx);}
        catch(\Throwable $e){error_log('VenVia Interface home failed; Core fallback used');return null;}
    }
    public static function shell(PDO $db,array $ctx,string $body): ?string {
        $m=self::enabled($db)['venvia-interface']??null;
        $file=$m['ui']['shell']??null;if(!is_string($file))return null;
        $context=$ctx;$context['content_html']=$body;
        $context['navigation']=self::navigation($db,$ctx);
        try{return self::invoke('venvia-interface',$file,$context);}
        catch(\Throwable $e){error_log('VenVia Interface shell failed; Core fallback used');return null;}
    }
    public static function widgetsHtml(PDO $db,array $ctx): string {
        $html='';foreach(self::enabled($db) as $id=>$m)foreach(($m['ui']['widgets']??[]) as $w){
            if($w['area']!==$ctx['area'] || !self::can($ctx,$w['capability']??($ctx['area']==='backend'?'backend.access':'frontend.access')))continue;
            try {$html.=self::invoke($id,$w['file'],$ctx);}catch(\Throwable $e){error_log('VenVia widget unavailable: '.$id);}
        }return $html;
    }
}
