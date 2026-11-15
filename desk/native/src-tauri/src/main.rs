#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]
use serde::{Deserialize, Serialize};
use std::{fs, net::{TcpListener, SocketAddr}, path::PathBuf};
use sysinfo::{System, Disks};
use tauri::Manager;

#[derive(Serialize)]
struct Report { os: String, arch: String, cpu: String, logical_cpus: usize, ram_mib: u64, disks: Vec<Disk>, ports: Vec<Port>, processes: Vec<String> }
#[derive(Serialize)]
struct Disk { mount: String, free_mib: u64, total_mib: u64 }
#[derive(Serialize)]
struct Port { number: u16, result: String }
#[derive(Serialize, Deserialize, Default)]
struct Settings { mode: String }

fn valid_mode(mode: &str) -> bool { matches!(mode, "server" | "client") }
fn settings_path(app: &tauri::AppHandle) -> Result<PathBuf, String> {
    Ok(app.path().app_config_dir().map_err(|e| e.to_string())?.join("settings.json"))
}
#[tauri::command]
fn load_settings(app: tauri::AppHandle) -> Result<Settings, String> {
    match fs::read(settings_path(&app)?) {
        Ok(bytes) => {
            let s: Settings = serde_json::from_slice(&bytes).map_err(|e| e.to_string())?;
            if !valid_mode(&s.mode) { return Err("Modalità non valida".into()); }
            Ok(s)
        }
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(Settings::default()),
        Err(e) => Err(e.to_string())
    }
}
#[tauri::command]
fn save_settings(app: tauri::AppHandle, mode: String) -> Result<(), String> {
    if !valid_mode(&mode) { return Err("Modalità non valida".into()); }
    let p = settings_path(&app)?;
    fs::create_dir_all(p.parent().ok_or("Percorso non valido")?).map_err(|e| e.to_string())?;
    fs::write(p, serde_json::to_vec_pretty(&Settings { mode }).map_err(|e| e.to_string())?).map_err(|e| e.to_string())
}
#[tauri::command]
async fn diagnostics() -> Result<Report, String> {
    tauri::async_runtime::spawn_blocking(|| {
        let mut s = System::new_all(); s.refresh_all();
        let disks = Disks::new_with_refreshed_list().list().iter().map(|d| Disk { mount: d.mount_point().display().to_string(), free_mib: d.available_space()/1048576, total_mib: d.total_space()/1048576 }).collect();
        let ports = [80,443,3306].into_iter().map(|number| {
            let addr = SocketAddr::from(([127,0,0,1],number));
            let result = match TcpListener::bind(addr) { Ok(_) => "Bind locale riuscito", Err(_) => "Bind locale non disponibile" }.to_string();
            Port { number, result }
        }).collect();
        let mut processes: Vec<String> = s.processes().values().filter_map(|p| {
            let n = p.name().to_string_lossy().to_string();
            let lower = n.to_lowercase();
            if ["php","nginx","maria","mysql","httpd","apache","w3wp"].iter().any(|x| lower.starts_with(*x)) { Some(n) } else { None }
        }).collect(); processes.sort(); processes.dedup();
        Report { os: System::long_os_version().unwrap_or_else(|| "Non rilevato".into()), arch: std::env::consts::ARCH.into(), cpu: s.cpus().first().map(|c| c.brand().to_string()).unwrap_or_default(), logical_cpus: s.cpus().len(), ram_mib: s.total_memory()/1048576, disks, ports, processes }
    }).await.map_err(|e| e.to_string())
}
fn main() {
    tauri::Builder::default().invoke_handler(tauri::generate_handler![diagnostics,load_settings,save_settings]).run(tauri::generate_context!()).expect("Avvio VenVia Desk non riuscito");
}
#[cfg(test)]
mod tests { use super::*; #[test] fn modes() { assert!(valid_mode("server")); assert!(valid_mode("client")); assert!(!valid_mode("")); assert!(!valid_mode("../server")); } }
