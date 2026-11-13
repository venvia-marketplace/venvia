#!/usr/bin/env python3
"""VenVia trusted offline candidate preparation. Does NOT install/activate anything."""
import argparse, hashlib, importlib.util, json, os, pathlib, re, stat, sys, tempfile, urllib.request, zipfile
SCANNER='/usr/local/lib/venvia/staging-tools/github_app_scanner.py'
DEST=pathlib.Path('/srv/venvia-data/staging/release-candidates')
TAG=re.compile(r'^v?\d+\.\d+\.\d+(?:\.\d+)?(?:-[A-Za-z0-9.-]+)?$')
SAFE=re.compile(r'^(?:src|assets)/[A-Za-z0-9_./-]+$')
MAX=32*1024*1024
class Refused(Exception): pass

def scanner():
    spec=importlib.util.spec_from_file_location('venvia_reader',SCANNER)
    if not spec or not spec.loader: raise Refused('Scanner unavailable')
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
    return mod

def no_symlink(p):
    if p.is_symlink():raise Refused('Symlink forbidden')

def validate_zip(blob, tag):
    import io
    with zipfile.ZipFile(io.BytesIO(blob)) as z:
        entries=z.infolist(); names=[x.filename for x in entries]
        if not entries or len(entries)>250 or len(names)!=len(set(names)) or 'venvia-release.json' not in names:raise Refused('Invalid archive manifest')
        if sum(x.file_size for x in entries)>MAX:raise Refused('Archive expanded size limit')
        for x in entries:
            if x.is_dir() or x.file_size>MAX or x.flag_bits&1 or (x.external_attr>>16)&0o170000==0o120000:raise Refused('Unsafe archive entry')
            if x.filename!='venvia-release.json' and (not SAFE.fullmatch(x.filename) or '..' in pathlib.PurePosixPath(x.filename).parts):raise Refused('Unexpected package path')
        m=json.loads(z.read('venvia-release.json'))
        if not isinstance(m,dict) or m.get('version')!=tag:raise Refused('Manifest version mismatch')
        if not re.fullmatch('[0-9a-f]{40}',str(m.get('commit',''))):raise Refused('Commit missing')
        files=m.get('files')
        if not isinstance(files,dict) or set(files)!=set(names)-{'venvia-release.json'}:raise Refused('Manifest file set mismatch')
        for name, digest in files.items():
            if not re.fullmatch('[0-9a-f]{64}',str(digest)) or hashlib.sha256(z.read(name)).hexdigest()!=digest:raise Refused('File checksum mismatch')
        return m

def prepare(tag):
    if os.geteuid()!=0:raise Refused('Run as root')
    if not TAG.fullmatch(tag):raise Refused('Invalid release tag')
    s=scanner();cfg=s.load_config();token,_=s.get_installation_token(cfg);s.verify_installation(cfg,token)
    from urllib.parse import quote
    rel=s.api_request('/repos/venvia-marketplace/venvia/releases/tags/'+quote(tag,safe=''),token)
    if not isinstance(rel,dict) or rel.get('draft') or rel.get('prerelease') or rel.get('tag_name')!=tag:raise Refused('Release not approved for candidate preparation')
    assets=[a for a in rel.get('assets',[]) if isinstance(a,dict) and a.get('name')==('VenVia-'+tag.lstrip('v')+'.zip')]
    if len(assets)!=1:raise Refused('Exactly one canonical ZIP asset required')
    a=assets[0];digest=a.get('digest');asset_id=a.get('id');size=a.get('size')
    if not isinstance(digest,str) or not re.fullmatch('sha256:[a-fA-F0-9]{64}',digest) or not isinstance(asset_id,int) or not isinstance(size,int) or size>MAX or size<1:raise Refused('Release asset lacks valid SHA256 digest')
    # Only GitHub's fixed API hostname; no redirects to arbitrary hosts authorized.
    url=f'https://api.github.com/repos/venvia-marketplace/venvia/releases/assets/{asset_id}'
    headers={**s.HEADERS,'Authorization':'Bearer '+token,'Accept':'application/octet-stream'}
    opener=urllib.request.build_opener(urllib.request.HTTPHandler(),urllib.request.HTTPSHandler())
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,req,fp,code,msg,hdrs,newurl):raise Refused('Redirect refused; download requires vetted redirect handler')
    opener.add_handler(NoRedirect())
    try:
        with opener.open(urllib.request.Request(url,headers=headers),timeout=25) as r:blob=r.read(MAX+1)
    except Exception as exc:raise Refused('Asset retrieval failed: verify GitHub asset download policy') from None
    if len(blob)>MAX or hashlib.sha256(blob).hexdigest()!=digest.split(':')[1].lower():raise Refused('Asset digest mismatch')
    manifest=validate_zip(blob,tag)
    no_symlink(DEST);DEST.mkdir(parents=True,exist_ok=True,mode=0o700)
    if DEST.stat().st_uid!=0 or DEST.stat().st_mode&0o077:raise Refused('Candidate directory must be root-only')
    folder=DEST/('github-'+tag+'-'+digest[7:19]);no_symlink(folder)
    if folder.exists():raise Refused('Candidate already exists; no overwrite')
    temp=pathlib.Path(tempfile.mkdtemp(prefix='.github-prepare-',dir=DEST))
    try:
        (temp/'package.zip').write_bytes(blob)
        (temp/'manifest.json').write_text(json.dumps({'tag':tag,'sha256':digest,'commit':manifest['commit'],'state':'VERIFIED_NOT_INSTALLED','origin':'github-release'},indent=2)+'\n')
        os.chmod(temp/'package.zip',0o600);os.chmod(temp/'manifest.json',0o600)
        os.rename(temp,folder)
    finally:
        if temp.exists():
            import shutil;shutil.rmtree(temp)
    return {'result':'GITHUB_CANDIDATE_VERIFIED','candidate':folder.name,'tag':tag,'web_runtime_changed':False,'deployment_performed':False}

def main():
    p=argparse.ArgumentParser();p.add_argument('action',choices=['preflight','prepare']);p.add_argument('--tag');a=p.parse_args()
    try:
        if a.action=='preflight':
            if os.geteuid()!=0:raise Refused('Root required')
            s=scanner();s.load_config();print(json.dumps({'result':'CANDIDATE_PREFLIGHT_OK','network_called':False,'deployment_performed':False}));return 0
        if not a.tag:raise Refused('--tag required')
        print(json.dumps(prepare(a.tag)));return 0
    except (Refused,ValueError,OSError,zipfile.BadZipFile) as e:
        print(json.dumps({'result':'BLOCKED','reason':str(e)[:180],'deployment_performed':False}));return 2
if __name__=='__main__':sys.exit(main())
