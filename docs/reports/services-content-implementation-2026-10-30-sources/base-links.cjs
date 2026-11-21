const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const root='C:/Projects/nikolacars',b=root+'/.local-backup-20261030-services-content',m=JSON.parse(fs.readFileSync(b+'/manifest.json','utf8'));const sha=x=>crypto.createHash('sha256').update(x).digest('hex');
function edit(rel,fn){const p=root+'/'+rel,t=fs.readFileSync(p,'utf8'),n=fn(t);if(t===n)return;if(!m.files.some(x=>x.path===rel)){fs.mkdirSync(path.dirname(b+'/before/'+rel),{recursive:true});fs.writeFileSync(b+'/before/'+rel,t);m.files.push({path:rel,existed:true,before_sha256:sha(t)});}fs.writeFileSync(p,n);}
const bases={'tesla-electricmotor-repair':'tesla-electricmotor-repair','tesla-battery-repair':'tesla-battery-repair','repair-tesla-door-handle':'repair-tesla-door-handle','tesla-subframe-repair':'tesla-subframe-repair','vidnovlennya-sertyfikativ-tesla':'vidnovlennya-sertyfikativ-tesla','firmware-auto':'firmware-auto','prigon-tesla-usa':'prigon-usa'};
for(const [slug,file]of Object.entries(bases))edit('resources/views/services/'+file+'.blade.php',t=>t.replace("@push('scripts')","@include('partials.service-related', ['serviceSlug' => '"+slug+"'])\n\n@push('scripts')"));
edit('resources/views/services/tesla-service.blade.php',t=>{
t=t.replace('  <section class="service-final">',"  @if(!$isTargetedPage || trim($isRu ? ($serviceData['final_html_ru'] ?? '') : ($serviceData['final_html_uk'] ?? '')) !== '')\n  <section class=\"service-final\">");
t=t.replace("\n</section>\n\n@push('scripts')","\n  @endif\n</section>\n\n@if(!$isTargetedPage)\n  @include('partials.service-related', ['serviceSlug' => 'tesla-service'])\n@endif\n\n@push('scripts')");
return t;});
const rel='resources/views/partials/service-related.blade.php';m.files.push({path:rel,existed:false,before_sha256:null});for(const e of m.files)e.after_sha256=sha(fs.readFileSync(root+'/'+e.path));fs.writeFileSync(b+'/manifest.json',JSON.stringify(m,null,2));console.log(m.files.length+' runtime files');
