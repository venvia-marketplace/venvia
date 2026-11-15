"use strict";
const invoke = window.__TAURI__.core.invoke;
const status = document.getElementById("status"), report = document.getElementById("report"), scan = document.getElementById("scan"), save = document.getElementById("save");
async function refresh(){
 scan.disabled=true; report.textContent="Diagnostica in corso...";
 try{
  const r=await invoke("diagnostics");
  report.textContent=[r.os,r.arch,r.cpu,"CPU logiche: "+r.logical_cpus,"RAM MiB: "+r.ram_mib,...r.disks.map(d=>d.mount+" — liberi "+d.free_mib+" MiB / "+d.total_mib),...r.ports.map(p=>"TCP "+p.number+": "+p.result), "Processi runtime rilevati: "+(r.processes.join(", ")||"nessuno"),"Controlli indicativi: non attestano compatibilità o stato dei servizi."].join("\n");
 }catch(e){report.textContent="Errore diagnostica: "+String(e);}finally{scan.disabled=false;}
}
save.addEventListener("click",async()=>{const m=document.querySelector("input[name=mode]:checked");if(!m){status.textContent="Seleziona una modalità.";return;}save.disabled=true;try{await invoke("save_settings",{mode:m.value});status.textContent="Modalità salvata. Attivazione runtime prevista in una macro successiva.";}catch(e){status.textContent=String(e);}finally{save.disabled=false;}});
scan.addEventListener("click",refresh);
invoke("load_settings").then(s=>{for(const el of document.querySelectorAll("input[name=mode]"))el.checked=el.value===s.mode;}).catch(e=>{status.textContent="Configurazione: "+String(e);});
refresh();