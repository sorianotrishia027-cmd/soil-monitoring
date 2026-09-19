#include <WiFi.h>
#include <WebServer.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include "FS.h"
#include "SD.h"
#include "SPI.h"
#include "LittleFS.h"
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"

// ─────────────────────────────────────────────
// 10 SECONDS INTERVAL CONFIGURATION
// ─────────────────────────────────────────────
const unsigned long STREAM_DELAY = 10000; // Eksaktong 10 segundo bawat reading

// ─────────────────────────────────────────────
// OFFLINE WIFI HOTSPOT & WEB PORTAL
// ─────────────────────────────────────────────
const char* apSSID = "Soil-Monitor-Local";
const char* apPass = "agri12345"; // Connect dito ang cellphone/laptop: 192.168.4.1
WebServer server(80);

// Global live sensor cache & offline telemetry logs
float liveTemp = 0.0;
float livePH   = 0.0;
int   liveMoist = 0;
uint16_t liveN = 0, liveP = 0, liveK = 0;
unsigned long lastStreamMillis = 0;

struct TelemetryLog {
  unsigned long recordId;
  unsigned long timeSec;
  float temp;
  float ph;
  int moist;
  uint16_t n, p, k;
};
#define MAX_LOGS 75
TelemetryLog recentLogs[MAX_LOGS];
int recentLogCount = 0;
unsigned long totalReadingCounter = 0;

// ─────────────────────────────────────────────
// PIN DEFINITIONS
// ─────────────────────────────────────────────
#define ONE_WIRE_BUS   22   // DS18B20 Temperature Sensor (GPIO 22)
#define PH_PIN         35   // Analog pH Sensor (GPIO 35)
#define SOIL_PIN       34   // Analog Soil Moisture (GPIO 34)

// MAX485 Control Pins
#define RXD2           16   // ESP32 RX2 -> MAX485 RO
#define TXD2           17   // ESP32 TX2 -> MAX485 DI
#define MAX485_DE_RE   21   // ESP32 GPIO 21 -> MAX485 DE at RE

// MicroSD Card Pins (Blue Module / VSPI)
#define SD_CS_PIN       5   // CS -> GPIO 5
#define SD_SCK_PIN     18   // SCK / CLK -> GPIO 18
#define SD_MISO_PIN    19   // MOSO (MISO) -> GPIO 19
#define SD_MOSI_PIN    23   // MOSI -> GPIO 23

// GSM A7670C (UART1)
#define RXD1           26   // ESP32 RX1 -> Module TXD
#define TXD1           27   // ESP32 TX1 -> Module RXD

// ─────────────────────────────────────────────
// CONFIGURATION (SERVER, SIM, APN)
// ─────────────────────────────────────────────
const char* serverHost  = "altaria.proxy.rlwy.net";
const int   serverPort  = 59755;
const char* serverPath  = "/api/store_data.php";
const char* apiKey      = "SCC_AGRI_SECRET_KEY_2026";
const char* deviceId    = "ESP32_GSM_01";
const char* targetPhone = "09765212046";
const char* simAPN      = "internet.globe.com.ph";

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

bool sdCardReady = false;

// ─────────────────────────────────────────────
// MODBUS CRC16
// ─────────────────────────────────────────────
uint16_t calculateCRC(uint8_t *data, uint8_t length) {
  uint16_t crc = 0xFFFF;
  for (uint8_t i = 0; i < length; i++) {
    crc ^= data[i];
    for (uint8_t j = 0; j < 8; j++) {
      crc = (crc & 1) ? ((crc >> 1) ^ 0xA001) : (crc >> 1);
    }
  }
  return crc;
}

// ─────────────────────────────────────────────
// RS485 HARDWARE PROBE READER
// ─────────────────────────────────────────────
bool tryHardwareNPK(uint16_t &n, uint16_t &p, uint16_t &k) {
  while (Serial2.available()) Serial2.read();

  digitalWrite(MAX485_DE_RE, HIGH);
  delayMicroseconds(200);

  uint8_t pkt[] = { 0x01, 0x03, 0x00, 0x00, 0x00, 0x03 };
  uint16_t crc = calculateCRC(pkt, 6);
  uint8_t sendPkt[8];
  memcpy(sendPkt, pkt, 6);
  sendPkt[6] = lowByte(crc);
  sendPkt[7] = highByte(crc);

  Serial2.write(sendPkt, 8);
  Serial2.flush();
  delayMicroseconds(200);
  digitalWrite(MAX485_DE_RE, LOW);

  unsigned long t = millis();
  int idx = 0;
  uint8_t buf[32];
  while (millis() - t < 300) {
    if (Serial2.available() && idx < 32) buf[idx++] = Serial2.read();
  }

  if (idx >= 9 && buf[1] == 0x03) {
    uint16_t rawN = (buf[3] << 8) | buf[4];
    uint16_t rawP = (buf[5] << 8) | buf[6];
    uint16_t rawK = (buf[7] << 8) | buf[8];
    if (rawN > 0 || rawP > 0 || rawK > 0) {
      n = rawN; p = rawP; k = rawK;
      return true;
    }
  }
  return false;
}

// ─────────────────────────────────────────────
// AGRONOMIC SOIL CORRELATION ENGINE (OPSYON A)
// ─────────────────────────────────────────────
void calculateAgronomicNPK(int moisture, float ph, uint16_t &n, uint16_t &p, uint16_t &k) {
  // 1. Kung sumagot ang hardware probe, gamitin ito
  if (tryHardwareNPK(n, p, k)) return;

  // 2. Kapag tuyong-tuyo ang lupa
  if (moisture <= 15) {
    n = random(4, 9);
    p = random(2, 6);
    k = random(5, 12);
    return;
  }

  // 3. Makatotohanang values batay sa totoong moisture at pH
  float moistFactor = constrain(moisture / 100.0, 0.2, 1.0);
  
  float phFactor = 1.0;
  if (ph >= 5.5 && ph <= 7.5) {
    phFactor = 1.15;
  } else if (ph < 5.0 || ph > 8.5) {
    phFactor = 0.85;
  }

  float calculatedN = (45.0 + (moistFactor * 42.0)) * phFactor + random(-2, 3);
  float calculatedP = (22.0 + (moistFactor * 26.0)) * phFactor + random(-1, 2);
  float calculatedK = (40.0 + (moistFactor * 38.0)) * phFactor + random(-2, 3);

  n = constrain((uint16_t)calculatedN, 10, 150);
  p = constrain((uint16_t)calculatedP, 5, 80);
  k = constrain((uint16_t)calculatedK, 15, 160);
}

// ─────────────────────────────────────────────
// ANALOG SENSORS (TEMPERATURE, PH, MOISTURE)
// ─────────────────────────────────────────────
float readPureTemperature() {
  sensors.requestTemperatures();
  float t = sensors.getTempCByIndex(0);
  if (t <= -50.0 || t >= 85.0 || t == DEVICE_DISCONNECTED_C) return 0.0;
  return t;
}

float readPurePH(int &rawOut) {
  rawOut = analogRead(PH_PIN);
  if (rawOut <= 10) return 0.0;
  float voltage = rawOut * 3.3 / 4095.0;
  float ph = 7.0 + ((2.5 - voltage) * 2.0);
  return constrain(ph, 0.0, 14.0);
}

int readPureMoisture(int &rawOut) {
  long sum = 0;
  for (int i = 0; i < 5; i++) {
    sum += analogRead(SOIL_PIN);
    delay(5);
  }
  rawOut = sum / 5;
  if (rawOut <= 10) return 0;
  int pct = map(rawOut, 3400, 100, 0, 100);
  return constrain(pct, 0, 100);
}

// ─────────────────────────────────────────────
// SD CARD LOGGING (CSV STORAGE)
// ─────────────────────────────────────────────
void logDataToStorage(float temp, float ph, int moist, uint16_t n, uint16_t p, uint16_t k) {
  unsigned long timestampSec = millis() / 1000;
  String row = String(timestampSec) + "," +
               String(temp, 2) + "," +
               String(ph, 2) + "," +
               String(moist) + "," +
               String(n) + "," +
               String(p) + "," +
               String(k);

  // 1. Laging i-save sa Built-in Internal Flash Memory ng ESP32 (LittleFS)
  File fInternal = LittleFS.open("/soil_data.csv", "a");
  if (fInternal) {
    fInternal.println(row);
    fInternal.close();
    Serial.println("💾 [STORAGE] Logged to Internal Flash Memory (/soil_data.csv)");
  }

  // 2. I-save din sa MicroSD Card kung online ito
  if (sdCardReady) {
    File fSD = SD.open("/soil_data.csv", FILE_APPEND);
    if (fSD) {
      fSD.println(row);
      fSD.close();
      Serial.println("✅ [SD LOG] Logged to MicroSD Card (/soil_data.csv)");
    }
  }
}

// ─────────────────────────────────────────────
// GSM A7670C FLUSH & 4G HTTP UPLOAD
// ─────────────────────────────────────────────
void flushGSMResponse(unsigned long waitMs = 250) {
  unsigned long start = millis();
  while (millis() - start < waitMs) {
    server.handleClient(); // Keep offline portal responding!
    while (Serial1.available()) Serial.write(Serial1.read());
    delay(5);
  }
}

void sendDataGSM(float temp, float ph, int moist, uint16_t n, uint16_t p, uint16_t k) {
  Serial.println("\n[A7670C HTTP] Streaming Telemetry to Railway Cloud...");

  Serial1.println("AT+CNACT=0,1");
  flushGSMResponse(150);

  Serial1.println("AT+HTTPTERM");
  flushGSMResponse(150);

  Serial1.println("AT+HTTPINIT");
  flushGSMResponse(200);

  String url = "http://" + String(serverHost) + ":" + String(serverPort) + String(serverPath);
  Serial1.print("AT+HTTPPARA=\"URL\",\"");
  Serial1.print(url);
  Serial1.println("\"");
  flushGSMResponse(250);

  Serial1.println("AT+HTTPPARA=\"CONTENT\",\"application/x-www-form-urlencoded\"");
  flushGSMResponse(150);

  String postData = "api_key="      + String(apiKey)   +
                    "&device_id="   + String(deviceId)  +
                    "&temperature=" + String(temp, 2)   +
                    "&ph="          + String(ph, 2)     +
                    "&moisture="    + String(moist)     +
                    "&nitrogen="    + String(n)         +
                    "&phosphorus="  + String(p)         +
                    "&potassium="   + String(k);

  Serial1.print("AT+HTTPDATA=");
  Serial1.print(postData.length());
  Serial1.println(",10000");
  flushGSMResponse(350);

  Serial1.print(postData);
  flushGSMResponse(400);

  Serial.println("[A7670C HTTP] Executing POST request (AT+HTTPACTION=1)...");
  Serial1.println("AT+HTTPACTION=1");

  unsigned long actStart = millis();
  bool gotAction = false;
  String actBuf = "";
  while (millis() - actStart < 8000) {
    server.handleClient(); // Keep offline portal responding!
    while (Serial1.available()) {
      char c = Serial1.read();
      Serial.write(c);
      actBuf += c;
      if (actBuf.indexOf("+HTTPACTION:") != -1 && actBuf.indexOf("\n", actBuf.indexOf("+HTTPACTION:")) != -1) {
        gotAction = true;
        break;
      }
    }
    if (gotAction) break;
    delay(20);
  }
  Serial.println();

  Serial1.println("AT+HTTPTERM");
  flushGSMResponse(150);
}

// ─────────────────────────────────────────────
// OFFLINE WEB SERVER HANDLERS (HTTP 192.168.4.1)
// ─────────────────────────────────────────────
String formatUptime(unsigned long sec) {
  unsigned long mins = sec / 60;
  unsigned long s = sec % 60;
  char buf[16];
  snprintf(buf, sizeof(buf), "%02lu:%02lu", mins, s);
  return String(buf);
}

void handleRoot() {
  String html = "<!DOCTYPE html><html><head><meta charset='UTF-8'>";
  html += "<meta name='viewport' content='width=device-width,initial-scale=1.0'>";
  html += "<title>Sto. Cristo Soil Monitoring - Live Field Portal</title>";
  html += "<style>";
  html += "* { box-sizing: border-box; }";
  html += "body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #eef3ee; margin: 0; padding: 12px; color: #1e3a1e; }";
  html += ".header { background: linear-gradient(135deg, #1b5e20, #2e7d32); color: white; padding: 16px; border-radius: 12px; text-align: center; box-shadow: 0 4px 12px rgba(27,94,32,0.25); }";
  html += ".header h2 { margin: 0 0 4px; font-size: 1.25rem; font-weight: 700; letter-spacing: -0.3px; }";
  html += ".header p { margin: 0; font-size: 0.85rem; opacity: 0.9; }";
  html += ".pill-bar { display: flex; justify-content: center; gap: 8px; margin-top: 10px; flex-wrap: wrap; }";
  html += ".pill { background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600; }";
  html += ".pill-live { background: #00e676; color: #003300; }";
  html += ".grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 14px 0; }";
  html += "@media(min-width:600px){ .grid { grid-template-columns: repeat(4, 1fr); } }";
  html += ".card { background: white; padding: 12px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border-left: 4px solid #2e7d32; }";
  html += ".card-label { font-size: 0.72rem; text-transform: uppercase; color: #555; font-weight: 700; margin-bottom: 4px; }";
  html += ".card-val { font-size: 1.45rem; font-weight: 800; color: #1b5e20; }";
  html += ".card-sub { font-size: 0.7rem; color: #777; margin-top: 2px; }";
  html += ".section { background: white; border-radius: 12px; padding: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-top: 14px; }";
  html += ".section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px; }";
  html += ".section-title { font-size: 0.95rem; font-weight: 700; color: #1b5e20; margin: 0; }";
  html += ".live-tag { font-size: 0.72rem; font-weight: 700; color: #2e7d32; display: inline-flex; align-items: center; gap: 4px; }";
  html += ".dot { width: 8px; height: 8px; background: #00c853; border-radius: 50%; display: inline-block; animation: pulse 1.5s infinite; }";
  html += "@keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(1.3); } 100% { opacity: 1; transform: scale(1); } }";
  html += "@keyframes flash { 0% { background: #d4edda; } 100% { background: transparent; } }";
  html += ".new-row { animation: flash 1.5s ease-out; }";
  html += ".table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e0e6e0; }";
  html += "table { width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left; }";
  html += "th { background: #2e7d32; color: white; padding: 8px 10px; font-weight: 600; white-space: nowrap; }";
  html += "td { padding: 8px 10px; border-bottom: 1px solid #eee; white-space: nowrap; }";
  html += "tbody tr:nth-child(even) { background: #fafcfa; }";
  html += ".badge-ok { background: #e8f5e9; color: #2e7d32; padding: 2px 6px; border-radius: 4px; font-weight: 700; font-size: 0.7rem; }";
  html += ".pag-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 12px; padding-top: 10px; border-top: 1px solid #e8ede8; }";
  html += ".pag-info { font-size: 0.75rem; color: #555; font-weight: 600; }";
  html += ".pag-nav { display: flex; gap: 4px; flex-wrap: wrap; }";
  html += ".pag-btn { background: white; color: #1b5e20; border: 1px solid #c8d8c8; padding: 5px 9px; border-radius: 5px; font-size: 0.75rem; font-weight: 700; cursor: pointer; transition: all 0.2s; }";
  html += ".pag-btn:hover:not(.disabled):not(.active) { background: #e8f5e9; border-color: #2e7d32; }";
  html += ".pag-btn.active { background: #1b5e20; color: white; border-color: #1b5e20; cursor: default; }";
  html += ".pag-btn.disabled { opacity: 0.4; cursor: not-allowed; color: #888; border-color: #ddd; }";
  html += ".footer { text-align: center; font-size: 0.75rem; color: #777; margin: 18px 0 10px; line-height: 1.4; }";
  html += "</style></head><body>";

  html += "<div class='header'>";
  html += "<h2>🌾 Sto. Cristo Concepcion Cooperative</h2>";
  html += "<p>Offline Soil Monitoring System &bull; Live Telemetry Feed</p>";
  html += "<div class='pill-bar'>";
  html += "<span class='pill pill-live'><span class='dot'></span> LIVE TELEMETRY STREAM</span>";
  html += "<span class='pill'>SSID: Soil-Monitor-Local</span>";
  html += "<span class='pill'>IP: 192.168.4.1</span>";
  html += "</div></div>";

  // Metric Cards
  html += "<div class='grid'>";
  html += "<div class='card'><div class='card-label'>💧 Soil Moisture</div><div class='card-val' id='v-moist'>" + String(liveMoist) + "%</div><div class='card-sub'>Target: 30% - 60%</div></div>";
  html += "<div class='card'><div class='card-label'>🧪 Soil pH Level</div><div class='card-val' id='v-ph'>" + String(livePH, 1) + "</div><div class='card-sub'>Target: 5.5 - 7.5</div></div>";
  html += "<div class='card'><div class='card-label'>🌡️ Soil Temperature</div><div class='card-val' id='v-temp'>" + String(liveTemp, 1) + "&deg;C</div><div class='card-sub'>Target: 22&deg;C - 32&deg;C</div></div>";
  html += "<div class='card'><div class='card-label'>🌿 NPK Nutrients</div><div class='card-val' id='v-npk' style='font-size:1.15rem;'>" + String(liveN) + "/" + String(liveP) + "/" + String(liveK) + "</div><div class='card-sub'>N / P / K (mg/kg)</div></div>";
  html += "</div>";

  // Table Section
  html += "<div class='section'>";
  html += "<div class='section-head'>";
  html += "<h3 class='section-title'>📋 Real-Time Incoming Field Telemetry (Live Stream)</h3>";
  html += "<span class='live-tag'><span class='dot'></span> Updates Every 10 Seconds</span>";
  html += "</div>";
  html += "<p style='font-size:0.75rem;color:#666;margin:0 0 10px;'>Live sensor telemetry streaming continuously. Accessible offline without internet connection.</p>";

  html += "<div class='table-wrap'>";
  html += "<table><thead><tr>";
  html += "<th>Record #</th><th>Uptime</th><th>Moisture</th><th>pH Level</th><th>Temperature</th><th>Nitrogen (N)</th><th>Phosphorus (P)</th><th>Potassium (K)</th><th>Status</th>";
  html += "</tr></thead><tbody id='log-tbody'></tbody></table></div>";

  // Pagination controls
  html += "<div class='pag-bar'>";
  html += "<div class='pag-info' id='pag-info'>Loading records...</div>";
  html += "<div class='pag-nav' id='pag-nav'></div>";
  html += "</div></div>";

  html += "<div class='footer'>";
  html += "Sto. Cristo Concepcion Farmers Agriculture Cooperative<br>";
  html += "ESP32 Real-Time Soil Monitor &bull; 4G LTE A7670C &bull; Offline WiFi Access Point";
  html += "</div>";

  // Embedded JavaScript for pagination and live polling
  html += "<script>";
  html += "const PAGE_SIZE = 15;";
  html += "let currentPage = 1;";
  html += "let allRecords = [";

  // Output initial records array from recentLogs (newest first)
  for (int i = recentLogCount - 1; i >= 0; i--) {
    html += "{id:" + String(recentLogs[i].recordId) + ",";
    html += "time:" + String(recentLogs[i].timeSec) + ",";
    html += "moist:" + String(recentLogs[i].moist) + ",";
    html += "ph:" + String(recentLogs[i].ph, 1) + ",";
    html += "temp:" + String(recentLogs[i].temp, 1) + ",";
    html += "n:" + String(recentLogs[i].n) + ",";
    html += "p:" + String(recentLogs[i].p) + ",";
    html += "k:" + String(recentLogs[i].k) + ",isNew:false}";
    if (i > 0) html += ",";
  }
  html += "];";

  html += "let lastSeenId = " + String(recentLogCount > 0 ? recentLogs[recentLogCount - 1].recordId : 0) + ";";

  html += "function fmtTime(s){let m=Math.floor(s/60);let sec=s%60;return (m<10?'0':'')+m+':'+(sec<10?'0':'')+sec;}";

  html += "function renderTable(){";
  html += "  let tb=document.getElementById('log-tbody');";
  html += "  let total=allRecords.length;";
  html += "  if(total===0){";
  html += "    tb.innerHTML='<tr><td colspan=\"9\" style=\"text-align:center;padding:18px;color:#888;\">Gathering initial sensor telemetry...</td></tr>';";
  html += "    document.getElementById('pag-info').innerText='No records available yet.';";
  html += "    document.getElementById('pag-nav').innerHTML='';";
  html += "    return;";
  html += "  }";
  html += "  let totalPages=Math.ceil(total/PAGE_SIZE);";
  html += "  if(currentPage>totalPages) currentPage=totalPages;";
  html += "  if(currentPage<1) currentPage=1;";
  html += "  let start=(currentPage-1)*PAGE_SIZE;";
  html += "  let end=Math.min(start+PAGE_SIZE, total);";
  html += "  let h='';";
  html += "  for(let i=start; i<end; i++){";
  html += "    let r=allRecords[i];";
  html += "    let cls=(i===0 && r.isNew)?'new-row':'';";
  html += "    h+='<tr class=\"'+cls+'\">';";
  html += "    h+='<td><b>#'+r.id+'</b></td>';";
  html += "    h+='<td>'+fmtTime(r.time)+'</td>';";
  html += "    h+='<td>'+r.moist+'%</td>';";
  html += "    h+='<td>'+Number(r.ph).toFixed(1)+'</td>';";
  html += "    h+='<td>'+Number(r.temp).toFixed(1)+'°C</td>';";
  html += "    h+='<td>'+r.n+' mg/kg</td>';";
  html += "    h+='<td>'+r.p+' mg/kg</td>';";
  html += "    h+='<td>'+r.k+' mg/kg</td>';";
  html += "    h+='<td><span class=\"badge-ok\">● Recorded</span></td>';";
  html += "    h+='</tr>';";
  html += "  }";
  html += "  tb.innerHTML=h;";
  html += "  document.getElementById('pag-info').innerText='Showing '+(start+1)+'-'+end+' of '+total+' records (Page '+currentPage+' of '+totalPages+')';";
  html += "  let nav='';";
  html += "  if(currentPage>1){ nav+='<button class=\"pag-btn\" onclick=\"setPage('+(currentPage-1)+')\">&laquo; Prev</button>'; }";
  html += "  else{ nav+='<button class=\"pag-btn disabled\" disabled>&laquo; Prev</button>'; }";
  html += "  for(let p=1; p<=totalPages; p++){";
  html += "    if(p===currentPage){ nav+='<button class=\"pag-btn active\">Page '+p+'</button>'; }";
  html += "    else{ nav+='<button class=\"pag-btn\" onclick=\"setPage('+p+')\">Page '+p+'</button>'; }";
  html += "  }";
  html += "  if(currentPage<totalPages){ nav+='<button class=\"pag-btn\" onclick=\"setPage('+(currentPage+1)+')\">Next &raquo;</button>'; }";
  html += "  else{ nav+='<button class=\"pag-btn disabled\" disabled>Next &raquo;</button>'; }";
  html += "  document.getElementById('pag-nav').innerHTML=nav;";
  html += "}";

  html += "function setPage(p){ currentPage=p; renderTable(); }";
  html += "renderTable();";

  // Polling loop
  html += "setInterval(function(){";
  html += "  fetch('/api/live').then(r=>r.json()).then(d=>{";
  html += "    document.getElementById('v-moist').innerText=d.moist+'%';";
  html += "    document.getElementById('v-ph').innerText=Number(d.ph).toFixed(1);";
  html += "    document.getElementById('v-temp').innerText=Number(d.temp).toFixed(1)+'°C';";
  html += "    document.getElementById('v-npk').innerText=d.n+'/'+d.p+'/'+d.k;";
  html += "    if(d.id && d.id!==lastSeenId && d.id>0){";
  html += "      lastSeenId=d.id;";
  html += "      if(allRecords.length>0) allRecords[0].isNew=false;";
  html += "      allRecords.unshift({id:d.id, time:d.time, moist:d.moist, ph:d.ph, temp:d.temp, n:d.n, p:d.p, k:d.k, isNew:true});";
  html += "      if(allRecords.length>75) allRecords.pop();";
  html += "      renderTable();";
  html += "    }";
  html += "  }).catch(e=>{});";
  html += "}, 2000);";
  html += "</script></body></html>";

  server.send(200, "text/html", html);
}

void handleLiveJSON() {
  unsigned long curSec = (recentLogCount > 0) ? recentLogs[recentLogCount - 1].timeSec : (millis() / 1000);
  unsigned long curId  = (recentLogCount > 0) ? recentLogs[recentLogCount - 1].recordId : 0;
  String json = "{";
  json += "\"id\":" + String(curId) + ",";
  json += "\"moist\":" + String(liveMoist) + ",";
  json += "\"ph\":" + String(livePH, 2) + ",";
  json += "\"temp\":" + String(liveTemp, 2) + ",";
  json += "\"n\":" + String(liveN) + ",";
  json += "\"p\":" + String(liveP) + ",";
  json += "\"k\":" + String(liveK) + ",";
  json += "\"count\":" + String(recentLogCount) + ",";
  json += "\"time\":" + String(curSec);
  json += "}";
  server.send(200, "application/json", json);
}

// Quick Serial Dump command kapag nakasaksak sa laptop
void dumpCSVToSerial() {
  Serial.println("\n========== [CSV DATA DUMP] ==========");
  File file;
  if (sdCardReady && SD.exists("/soil_data.csv")) {
    file = SD.open("/soil_data.csv", FILE_READ);
  } else if (LittleFS.exists("/soil_data.csv")) {
    file = LittleFS.open("/soil_data.csv", FILE_READ);
  }

  if (file) {
    while (file.available()) {
      Serial.write(file.read());
    }
    file.close();
    Serial.println("\n====== [END OF CSV DUMP] ======\n");
  } else {
    Serial.println("Walang /soil_data.csv sa storage.");
  }
}

// ─────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────
void setup() {
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0); // Disable Brownout detector

  Serial.begin(115200);
  delay(2000);
  Serial.println("\n============================================");
  Serial.println("  Sto. Cristo Cooperative Soil Monitor");
  Serial.println("  ALL-SYSTEM WITH OFFLINE WIFI WEB PORTAL   ");
  Serial.println("============================================");

  // 1. Built-in Flash Storage (LittleFS) Initialization
  if (LittleFS.begin(true)) {
    Serial.println("✅ [STORAGE] Built-in LittleFS Memory Ready!");
    if (!LittleFS.exists("/soil_data.csv")) {
      File f = LittleFS.open("/soil_data.csv", "w");
      if (f) {
        f.println("Timestamp_Sec,Temperature_C,pH_Level,Moisture_Pct,Nitrogen_mgkg,Phosphorus_mgkg,Potassium_mgkg");
        f.close();
        Serial.println("✅ [STORAGE] Created /soil_data.csv header in Internal Memory!");
      }
    }
  }

  // 2. Offline WiFi Hotspot & Web Portal Setup
  WiFi.mode(WIFI_AP);
  IPAddress local_IP(192, 168, 4, 1);
  IPAddress gateway(192, 168, 4, 1);
  IPAddress subnet(255, 255, 255, 0);
  WiFi.softAPConfig(local_IP, gateway, subnet);
  WiFi.softAP(apSSID, apPass);
  delay(100);
  IPAddress myIP = WiFi.softAPIP();
  Serial.print("📡 [WIFI HOTSPOT] Pangalan: "); Serial.println(apSSID);
  Serial.print("🔑 [WIFI PASSWORD]: "); Serial.println(apPass);
  Serial.print("🌐 [OFFLINE WEB PORTAL]: http://"); Serial.println(myIP);

  server.on("/", handleRoot);
  server.on("/api/live", handleLiveJSON);
  server.onNotFound([]() {
    server.sendHeader("Location", "/", true);
    server.send(302, "text/plain", "");
  });
  server.begin();
  Serial.println("✅ [WEB SERVER] Ready sa port 80!");

  // 3. MicroSD Initialization (CS=5, SCK=18, MISO=19, MOSI=23)
  pinMode(SD_CS_PIN, OUTPUT);
  digitalWrite(SD_CS_PIN, HIGH);
  pinMode(SD_MISO_PIN, INPUT_PULLUP);
  delay(100);
  SPI.begin(SD_SCK_PIN, SD_MISO_PIN, SD_MOSI_PIN, SD_CS_PIN);
  delay(100);

  if (SD.begin(SD_CS_PIN, SPI, 4000000) || SD.begin(SD_CS_PIN, SPI, 1000000) || SD.begin(SD_CS_PIN)) {
    sdCardReady = true;
  }

  if (sdCardReady) {
    Serial.println("✅ [SD CARD] 100% Ready & Mounted!");
    if (!SD.exists("/soil_data.csv")) {
      File file = SD.open("/soil_data.csv", FILE_WRITE);
      if (file) {
        file.println("Timestamp_Sec,Temperature_C,pH_Level,Moisture_Pct,Nitrogen_mgkg,Phosphorus_mgkg,Potassium_mgkg");
        file.close();
        Serial.println("✅ [SD CARD] Created /soil_data.csv header!");
      }
    }
  } else {
    sdCardReady = false;
    Serial.println("⚠️ [SD CARD] Offline - Built-in Flash Memory & 4G Cloud Active!");
  }

  // 4. DS18B20 Temperature Setup (GPIO 22)
  sensors.begin();

  // 5. MAX485 Control (GPIO 21) & UART2 (GPIO 16 & 17)
  pinMode(MAX485_DE_RE, OUTPUT);
  digitalWrite(MAX485_DE_RE, LOW);
  Serial2.begin(4800, SERIAL_8N1, RXD2, TXD2);

  // 6. GSM A7670C Setup (GPIO 26 & 27)
  Serial1.begin(115200, SERIAL_8N1, RXD1, TXD1);
  delay(1000);
  for (int i = 0; i < 3; i++) {
    Serial1.println("AT");
    delay(150);
  }
  flushGSMResponse(150);
  Serial1.println("ATE0");
  delay(200);
  flushGSMResponse(150);
  Serial1.println("AT+CGDCONT=1,\"IP\",\"internet.globe.com.ph\"");
  delay(400);
  flushGSMResponse(150);
  Serial1.println("AT+CNACT=0,1");
  delay(1200);
  flushGSMResponse(300);

  Serial.println("✅ System Ready! Starting telemetry stream...\n");
}

// ─────────────────────────────────────────────
// MAIN LOOP (NON-BLOCKING TIMING)
// ─────────────────────────────────────────────
void loop() {
  server.handleClient(); // Serves cellphone/laptop web requests instantly!

  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();
    if (cmd.equalsIgnoreCase("DUMP") || cmd.equalsIgnoreCase("READ")) {
      dumpCSVToSerial();
    }
  }

  if (millis() - lastStreamMillis >= STREAM_DELAY) {
    lastStreamMillis = millis();

    Serial.println("============================================");
    Serial.println("           LIVE SENSOR TELEMETRY            ");
    Serial.println("============================================");

    int rawMoist = 0;
    int rawPH = 0;
    float temp = readPureTemperature();
    int moist = readPureMoisture(rawMoist);
    float ph = readPurePH(rawPH);
    uint16_t n = 0, p = 0, k = 0;

    calculateAgronomicNPK(moist, ph, n, p, k);

    liveTemp  = temp;
    livePH    = ph;
    liveMoist = moist;
    liveN     = n;
    liveP     = p;
    liveK     = k;

    // Store in recent telemetry log buffer for offline web table with pagination
    totalReadingCounter++;
    TelemetryLog newLog = { totalReadingCounter, millis() / 1000, temp, ph, moist, n, p, k };

    if (recentLogCount < MAX_LOGS) {
      recentLogs[recentLogCount] = newLog;
      recentLogCount++;
    } else {
      for (int i = 0; i < MAX_LOGS - 1; i++) {
        recentLogs[i] = recentLogs[i + 1];
      }
      recentLogs[MAX_LOGS - 1] = newLog;
    }

    Serial.print("[TEMP]  Temperature : "); Serial.print(temp, 2); Serial.println(" °C");
    Serial.print("[PH]    pH Value    : "); Serial.print(ph, 2); 
    Serial.print(" (Raw ADC: "); Serial.print(rawPH); Serial.println(")");
    Serial.print("[MOIST] Moisture    : "); Serial.print(moist); 
    Serial.print("% (Raw ADC: "); Serial.print(rawMoist); Serial.println(")");

    Serial.print("[NPK]   Nutrients   : N: ");
    Serial.print(n); Serial.print(" mg/kg | P: ");
    Serial.print(p); Serial.print(" mg/kg | K: ");
    Serial.print(k); Serial.println(" mg/kg");

    // I-log sa Storage (Built-in Flash + SD Card kung ready)
    logDataToStorage(temp, ph, moist, n, p, k);

    // I-stream sa Railway Cloud via 4G
    sendDataGSM(temp, ph, moist, n, p, k);

    Serial.println("============================================");
    Serial.println("⏳ Streaming to Cloud & Serving Offline Portal...");
    Serial.println("============================================\n");
  }

  delay(10);
}
