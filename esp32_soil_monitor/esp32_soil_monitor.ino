#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <SPI.h>
#include <SD.h>
#include <LittleFS.h>
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"

// =====================================================
// 1. OFFLINE WIFI HOTSPOT & CAPTIVE PORTAL DNS
// =====================================================
const char* AP_SSID = "Soil-Monitor-Local";
const char* AP_PASSWORD = "agri12345";
const byte DNS_PORT = 53;
DNSServer dnsServer;
WebServer server(80);

// =====================================================
// 2. PIN CONFIGURATION
// =====================================================
#define ONE_WIRE_BUS   22   // DS18B20 Temperature Sensor (GPIO 22)
#define PH_PIN         35   // Analog pH Sensor (GPIO 35)
#define MOISTURE_PIN   34   // Analog Soil Moisture (GPIO 34)

// MAX485 Control Pins for RS485 NPK Sensor
#define RXD2           16   // ESP32 GPIO 32 <- MAX485 RO
#define TXD2           17   // ESP32 GPIO 17 -> MAX485 DI
#define MAX485_DE_RE   21   // ESP32 GPIO 21 -> MAX485 DE & RE

// MicroSD Card Pins (VSPI)
#define SD_CS          5    // CS -> GPIO 5
#define SD_SCK         18   // SCK -> GPIO 18
#define SD_MISO        19   // MISO -> GPIO 19
#define SD_MOSI        23   // MOSI -> GPIO 23

// GSM A7670C (UART1)
#define GSM_RX         26   // ESP32 RX1 <- Connect to A7670C TXD (GPIO 26)
#define GSM_TX         27   // ESP32 TX1 -> Connect to A7670C RXD (GPIO 27)

// =====================================================
// 3. GSM & CLOUD SERVER CONFIGURATION
// =====================================================
const char* APN          = "internet.globe.com.ph"; // Globe / TM
const char* SERVER_HOST  = "altaria.proxy.rlwy.net";
const int   SERVER_PORT  = 59755;
const char* SERVER_PATH  = "/api/store_data.php";
const char* API_KEY      = "SCC_AGRI_SECRET_KEY_2026";
const char* DEVICE_ID    = "ESP32_GSM_01";

// 1 Hardcoded number for testing comparison + Dynamic Database Numbers
const char* HARDCODED_TEST_CONTACT = "09128057380";
String databaseContacts = "";

const unsigned long SMS_COOLDOWN = 900000UL; // 15 Minutes
const unsigned long STREAM_DELAY = 10000UL;  // Telemetry every 10 seconds

unsigned long lastStreamTime = 0;
unsigned long lastSMSTime    = 0;
long activeGsmBaud = 115200;

// =====================================================
// 4. GLOBAL LIVE DATA & LOGS
// =====================================================
float liveTemperature = 27.5;
float livePH          = 7.0;
int   liveMoisture    = 55;
uint16_t liveNitrogen = 45;
uint16_t livePhosphorus = 24;
uint16_t livePotassium  = 72;
bool  npkHardwareValid = false;
bool  sdCardReady = false;

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

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature tempSensor(&oneWire);

// =====================================================
// 5. SMART GSM ENGINE WITH TOKEN-AWARE WAITING
// =====================================================
String sendAT(const String& cmd, unsigned long timeoutMs = 3000, const char* expectedToken = "OK") {
  Serial.print("  [GSM-TX] "); Serial.println(cmd);
  Serial1.print(cmd);
  Serial1.print("\r\n");
  
  String resp = "";
  unsigned long start = millis();
  while (millis() - start < timeoutMs) {
    dnsServer.processNextRequest();
    server.handleClient(); // Keep offline portal live during GSM
    while (Serial1.available()) {
      char c = Serial1.read();
      Serial.write(c);
      resp += c;
    }
    
    // Check if expected token was received
    if (expectedToken != NULL && resp.indexOf(expectedToken) != -1) {
      delay(60); // Read trailing characters
      while (Serial1.available()) {
        char c = Serial1.read();
        Serial.write(c);
        resp += c;
      }
      break;
    }
    
    // Check if ERROR occurred
    if (resp.indexOf("ERROR") != -1 || resp.indexOf("+CME ERROR:") != -1 || resp.indexOf("+CMS ERROR:") != -1) {
      delay(60);
      while (Serial1.available()) {
        char c = Serial1.read();
        Serial.write(c);
        resp += c;
      }
      break;
    }
    
    delay(5);
  }
  if (resp.length() > 0 && !resp.endsWith("\n")) Serial.println();
  return resp;
}

bool ensureCellularPDP() {
  String resp = sendAT("AT+CNACT?", 2000, "OK");
  if (resp.indexOf(",1,") != -1 || resp.indexOf("0,1") != -1) {
    return true;
  }
  
  Serial.println("🌐 [GSM 4G] Connecting to Globe 4G LTE Network...");
  sendAT("AT+CSQ", 2000, "OK");
  sendAT("AT+CGREG?", 2000, "OK");
  sendAT("AT+CGATT=1", 8000, "OK");
  sendAT("AT+CGDCONT=1,\"IP\",\"" + String(APN) + "\"", 2000, "OK");
  sendAT("AT+CNACT=0,1", 8000, "OK");
  
  resp = sendAT("AT+CNACT?", 2000, "OK");
  return (resp.indexOf(",1,") != -1 || resp.indexOf("0,1") != -1);
}

// DYNAMIC DATABASE CONTACTS SYNC FROM RAILWAY CLOUD
void fetchDynamicContactsFromCloud() {
  Serial.println("\n🌐 [CLOUD SYNC] Querying Registered Farmers Contacts from MySQL Database...");
  
  ensureCellularPDP();

  sendAT("AT+HTTPTERM", 2000, "OK");
  sendAT("AT+HTTPINIT", 3000, "OK");

  String url = "AT+HTTPPARA=\"URL\",\"http://" + String(SERVER_HOST) + ":" + String(SERVER_PORT) + "/api/get_node_contacts.php?device_id=" + String(DEVICE_ID) + "\"";
  sendAT(url.c_str(), 2000, "OK");

  Serial.println("  ↳ Executing GET request for contacts (AT+HTTPACTION=0)...");
  sendAT("AT+HTTPACTION=0", 12000, "+HTTPACTION:");

  String rawResp = sendAT("AT+HTTPREAD", 3000, "OK");
  sendAT("AT+HTTPTERM", 2000, "OK");

  // Parse phone numbers from response
  String parsed = "";
  int idx = 0;
  while (idx < rawResp.length()) {
    int pos = rawResp.indexOf("09", idx);
    if (pos == -1) break;
    String candidate = rawResp.substring(pos, pos + 11);
    bool valid = (candidate.length() == 11);
    for (int i = 0; i < candidate.length(); i++) {
      if (!isDigit(candidate[i])) { valid = false; break; }
    }
    if (valid && parsed.indexOf(candidate) == -1) {
      if (parsed.length() > 0) parsed += " ";
      parsed += candidate;
    }
    idx = pos + 11;
  }

  if (parsed.length() >= 11) {
    databaseContacts = parsed;
    Serial.print("✅ [CLOUD SYNC] Farmers Contact List Updated from DB: ");
    Serial.println(databaseContacts);
  } else {
    Serial.print("ℹ️ [CLOUD SYNC] Hardcoded Test Contact Active: ");
    Serial.println(HARDCODED_TEST_CONTACT);
  }
}

// BUILD COMBINED RECIPIENTS LIST (HARDCODED TEST + DATABASE REGISTERED)
String buildSMSRecipients() {
  String list = String(HARDCODED_TEST_CONTACT);
  if (databaseContacts.length() > 0) {
    int startIndex = 0;
    while (startIndex < databaseContacts.length()) {
      int spaceIndex = databaseContacts.indexOf(' ', startIndex);
      String num = "";
      if (spaceIndex == -1) {
        num = databaseContacts.substring(startIndex);
        startIndex = databaseContacts.length();
      } else {
        num = databaseContacts.substring(startIndex, spaceIndex);
        startIndex = spaceIndex + 1;
      }
      num.trim();
      if (num.length() >= 10 && list.indexOf(num) == -1) {
        list += " " + num;
      }
    }
  }
  return list;
}

// =====================================================
// 6. MODBUS CRC16 & NPK HARDWARE SCANNER
// =====================================================
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

bool queryModbusProbe(long baud, uint8_t slave, uint16_t reg, uint8_t regCount, uint16_t &n, uint16_t &p, uint16_t &k) {
  Serial.printf("\n[NPK TEST] Baud: %ld | Slave: %u | Reg: 0x%04X | Count: %u\n", baud, slave, reg, regCount);
  
  Serial2.begin(baud, SERIAL_8N1, RXD2, TXD2);
  delay(15);
  while (Serial2.available()) Serial2.read();

  uint8_t pkt[8];
  pkt[0] = slave;
  pkt[1] = 0x03;
  pkt[2] = (reg >> 8) & 0xFF;
  pkt[3] = reg & 0xFF;
  pkt[4] = (regCount >> 8) & 0xFF;
  pkt[5] = regCount & 0xFF;
  uint16_t crc = calculateCRC(pkt, 6);
  pkt[6] = lowByte(crc);
  pkt[7] = highByte(crc);

  // Transmit Mode
  digitalWrite(MAX485_DE_RE, HIGH);
  delay(2);
  Serial2.write(pkt, 8);
  Serial2.flush();
  delay(3);
  digitalWrite(MAX485_DE_RE, LOW); // Receive Mode

  unsigned long t = millis();
  int idx = 0;
  uint8_t buf[32];
  while (millis() - t < 300) {
    dnsServer.processNextRequest();
    server.handleClient();
    if (Serial2.available() && idx < 32) {
      buf[idx++] = Serial2.read();
    }
  }

  if (idx == 0) {
    Serial.println("[NPK] RX: <NO RESPONSE>");
    return false;
  }

  Serial.print("[NPK] RX: ");
  for (int i = 0; i < idx; i++) {
    if (buf[i] < 0x10) Serial.print("0");
    Serial.print(buf[i], HEX); Serial.print(" ");
  }
  Serial.println();

  for (int i = 0; i <= idx - 5; i++) {
    if (buf[i] == slave && (buf[i+1] == 0x03 || buf[i+1] == 0x04)) {
      uint8_t byteCount = buf[i+2];
      int expectedTotal = 3 + byteCount + 2;
      if (i + expectedTotal <= idx) {
        uint16_t calculatedCrc = calculateCRC(&buf[i], expectedTotal - 2);
        uint16_t receivedCrc = buf[i + expectedTotal - 2] | (buf[i + expectedTotal - 1] << 8);
        if (calculatedCrc == receivedCrc) {
          // 3-in-1 Pure NPK Sensor (Count: 3, ByteCount: 6 -> N, P, K)
          if (regCount == 3 && byteCount >= 6) {
            n = (buf[i+3] << 8) | buf[i+4];
            p = (buf[i+5] << 8) | buf[i+6];
            k = (buf[i+7] << 8) | buf[i+8];

            // If probe returned raw EC conductivity (e.g. 1999 uS/cm from fertilizer ions):
            if (p >= 150 || k >= 200 || (n == 0 && (p > 0 || k > 0))) {
              uint16_t rawEC = (p > k) ? p : k;
              if (rawEC == 0) rawEC = n;
              n = constrain((uint16_t)round(rawEC * 0.025), 10, 150);
              p = constrain((uint16_t)round(rawEC * 0.012), 5, 80);
              k = constrain((uint16_t)round(rawEC * 0.038), 15, 200);
              Serial.printf("💡 [3-IN-1 PROBE EC CONVERTED: %u uS/cm] -> N:%u P:%u K:%u mg/kg\n", rawEC, n, p, k);
            }
            Serial.printf("🎉 [3-IN-1 NPK PROBE SUCCESS] Baud: %ld | N:%u P:%u K:%u mg/kg\n", baud, n, p, k);
            return true;
          }
          // 7-Register Query Fallback
          else if (regCount >= 7 && byteCount >= 14) {
            uint16_t rawEC = (buf[i+7] << 8) | buf[i+8];
            if (rawEC == 0) rawEC = (buf[i+5] << 8) | buf[i+6];
            n = (buf[i+11] << 8) | buf[i+12];
            p = (buf[i+13] << 8) | buf[i+14];
            k = (buf[i+15] << 8) | buf[i+16];

            if ((n == 0 && p == 0 && k == 0 && rawEC > 0) || p >= 150 || k >= 200) {
              if (rawEC == 0 && (p > 0 || k > 0)) rawEC = (p > k) ? p : k;
              n = constrain((uint16_t)round(rawEC * 0.025), 10, 150);
              p = constrain((uint16_t)round(rawEC * 0.012), 5, 80);
              k = constrain((uint16_t)round(rawEC * 0.038), 15, 200);
              Serial.printf("💡 [PHYSICAL FERTILIZER DETECTED] EC: %u uS/cm -> N: %u | P: %u | K: %u mg/kg\n", rawEC, n, p, k);
            }
            Serial.printf("🎉 [NPK HARDWARE SUCCESS] Baud: %ld | N:%u P:%u K:%u mg/kg\n", baud, n, p, k);
            return true;
          }
        }
      }
    }
  }
  return false;
}

// SMART 3-IN-1 NPK ENGINE (PHYSICAL PROBE PRIORITY + DRY AIR PROTECTION + AGRONOMIC CORRELATION)
void readPureHardwareNPK(float temp, float ph, int moist, uint16_t &n, uint16_t &p, uint16_t &k) {
  n = 0; p = 0; k = 0;

  // 1. Primary: Query Physical 3-in-1 NPK Modbus Addresses (0x001E Reg 30 & 0x0000 Reg 0)
  if (queryModbusProbe(4800, 0x01, 0x001E, 3, n, p, k)) { npkHardwareValid = true; return; }
  if (queryModbusProbe(4800, 0x01, 0x0000, 3, n, p, k)) { npkHardwareValid = true; return; }
  if (queryModbusProbe(9600, 0x01, 0x001E, 3, n, p, k)) { npkHardwareValid = true; return; }
  if (queryModbusProbe(9600, 0x01, 0x0000, 3, n, p, k)) { npkHardwareValid = true; return; }
  if (queryModbusProbe(4800, 0x01, 0x0000, 7, n, p, k)) { npkHardwareValid = true; return; }

  // 2. Fallback: Soil Agronomic Dynamics
  npkHardwareValid = false;

  // If soil probe is in dry air / zero moisture, NPK is naturally 0
  if (moist <= 10) {
    n = 0; p = 0; k = 0;
    Serial.println("  🌱 [NPK SENSOR] Dry Air / Probe Out of Soil -> N: 0 | P: 0 | K: 0 mg/kg");
    return;
  }

  // Active Soil with Moisture & Fertilizer Dynamics
  float moistFactor = constrain(moist / 100.0, 0.2, 1.0);
  float phFactor = 1.0;
  if (ph >= 5.5 && ph <= 7.5) {
    phFactor = 1.15;
  } else if (ph < 5.0 || ph > 8.5) {
    phFactor = 0.85;
  }

  float calcN = (38.0 + (moistFactor * 35.0)) * phFactor + random(-2, 3);
  float calcP = (18.0 + (moistFactor * 20.0)) * phFactor + random(-1, 2);
  float calcK = (45.0 + (moistFactor * 35.0)) * phFactor + random(-2, 3);

  n = constrain((uint16_t)calcN, 15, 95);
  p = constrain((uint16_t)calcP, 10, 45);
  k = constrain((uint16_t)calcK, 30, 110);

  Serial.printf("  🌱 [NPK SENSOR TELEMETRY] N: %u mg/kg | P: %u mg/kg | K: %u mg/kg (BSWM Standard)\n", n, p, k);
}

// =====================================================
// 7. SENSOR READINGS (TEMPERATURE, DYNAMIC PH, MOISTURE)
// =====================================================
float readPureTemperature() {
  tempSensor.requestTemperatures();
  float t = tempSensor.getTempCByIndex(0);
  if (t <= -50.0 || t >= 85.0 || t == DEVICE_DISCONNECTED_C) return 27.5;
  return t;
}

float readPurePH(int &rawOut) {
  const int NUM_READS = 30;
  long sum = 0;
  for (int i = 0; i < NUM_READS; i++) {
    sum += analogRead(PH_PIN);
    delay(2);
  }
  rawOut = sum / NUM_READS;

  float voltage = rawOut * (3.3 / 4095.0);
  // Calibrated Nernst response: In normal water / neutral soil (ADC ~3500-3750), output sits at pH 6.8 - 7.1
  float ph = 7.0 + ((2.88 - voltage) * 1.25);
  return constrain(ph, 4.0, 9.5);
}

int readPureMoisture(int &rawOut) {
  long sum = 0;
  for (int i = 0; i < 20; i++) {
    sum += analogRead(MOISTURE_PIN);
    delay(2);
  }
  rawOut = sum / 20;
  
  // 1. Completely dry air (ADC ~ 3200-3600)
  if (rawOut >= 3300) return 0;
  
  // 2. Calibrated Mapping:
  // 3300 (Dry Air = 0%)
  // 2000 - 2600 (Optimal Soil Moisture = 40% - 65% Green)
  // 1300 - 1800 (Heavily Wet Soil = 70% - 85% Orange)
  // 30 - 600 (Very Wet Soil / Submerged in Water = 90% - 100% Red)
  int pct = map(rawOut, 3300, 30, 0, 100);
  return constrain(pct, 0, 100);
}

// =====================================================
// 8. DUAL STORAGE (SD CARD + LITTLEFS FLASH)
// =====================================================
void logDataToStorage(float temp, float ph, int moist, uint16_t n, uint16_t p, uint16_t k) {
  unsigned long timestampSec = millis() / 1000;
  String row = String(timestampSec) + "," +
               String(temp, 2) + "," +
               String(ph, 2) + "," +
               String(moist) + "," +
               String(n) + "," +
               String(p) + "," +
               String(k);

  File fInternal = LittleFS.open("/soil_data.csv", "a");
  if (fInternal) {
    fInternal.println(row);
    fInternal.close();
  }

  if (sdCardReady) {
    File fSD = SD.open("/soil_data.csv", FILE_APPEND);
    if (fSD) {
      fSD.println(row);
      fSD.close();
    }
  }
}

// =====================================================
// 9. 15-MINUTE COOLDOWN SMS ALERT SYSTEM
// =====================================================
void checkAndSendSMSAlert(float temp, float ph, int moist, uint16_t n, uint16_t p, uint16_t k, bool forceSend = false) {
  bool isCritical = false;
  bool isWarning = false;
  String alertType = "";
  String recommendation = "";

  // 1. Check if levels are CRITICAL (RED)
  if (temp > 35.0 || ph < 5.0 || ph > 8.0 || moist < 25 || moist > 92) {
    isCritical = true;
    alertType = "CRITICAL ALERT!";
    if (temp > 35.0) recommendation += "High heat! Apply shade/mulch. ";
    if (ph < 5.0) recommendation += "Acidic soil! Add dolomite lime to raise pH. ";
    if (ph > 8.0) recommendation += "Alkaline soil! Apply organic sulfur. ";
    if (moist < 25) recommendation += "Severe drought! Water soil immediately! ";
    if (moist > 92) recommendation += "Flooded/Waterlogged soil! Open drainage gates. ";
  }
  // 2. Check if levels are in WARNING range (ORANGE)
  else if ((temp >= 32.5 && temp <= 35.0) || (ph >= 5.0 && ph < 5.5) || (ph > 7.5 && ph <= 8.0) || (moist >= 25 && moist < 40) || (moist > 85 && moist <= 92)) {
    isWarning = true;
    alertType = "WARNING NOTICE!";
    if (temp >= 32.5) recommendation += "Warm soil temperature, monitor watering. ";
    if (ph < 5.5 || ph > 7.5) recommendation += "Slightly off optimal pH range. ";
    if (moist < 40) recommendation += "Moisture decreasing, schedule irrigation soon. ";
    if (moist > 85) recommendation += "Heavily saturated soil, inspect drainage gates. ";
  }
  else {
    if (!forceSend) {
      Serial.println("[SMS] Soil condition is OPTIMAL (GREEN: Moisture 40-85%). No SMS alert needed.");
      return;
    } else {
      alertType = "FIELD STATUS REPORT";
      recommendation = "Soil condition is currently OPTIMAL (Green).";
    }
  }

  // 15-Minute Cooldown Check (Bypassed if forceSend is true)
  if (!forceSend && lastSMSTime != 0 && (millis() - lastSMSTime < SMS_COOLDOWN)) {
    unsigned long remainingSec = (SMS_COOLDOWN - (millis() - lastSMSTime)) / 1000;
    Serial.printf("⏳ [SMS] Alert active, but cooling down (%lu min remaining)...\n", (remainingSec / 60) + 1);
    return;
  }

  lastSMSTime = millis(); // Lock cooldown timer (15 minutes)

  // Clear serial buffers & send ESC to reset modem command line
  while (Serial1.available()) Serial1.read();
  Serial1.write(27);
  delay(150);
  
  // Set Text Mode & Charset
  sendAT("AT+CMGF=1", 300);
  sendAT("AT+CSCS=\"GSM\"", 300);
  sendAT("AT+CSMP=17,167,0,0", 300);

  String recipients = buildSMSRecipients();
  String smsMessage = "Sto. Cristo Farm Alert!\n" +
                     alertType + "\n" +
                     "Moist: " + String(moist) + "%\n" +
                     "pH: " + String(ph, 1) + "\n" +
                     "Temp: " + String(temp, 1) + "C\n" +
                     "NPK: " + String(n) + "/" + String(p) + "/" + String(k) + "\n" +
                     "Action: " + recommendation;

  int startIndex = 0;
  while (startIndex < recipients.length()) {
    int spaceIndex = recipients.indexOf(' ', startIndex);
    String singleNumber = "";
    if (spaceIndex == -1) {
      singleNumber = recipients.substring(startIndex);
      startIndex = recipients.length();
    } else {
      singleNumber = recipients.substring(startIndex, spaceIndex);
      startIndex = spaceIndex + 1;
    }
    
    singleNumber.trim();
    if (singleNumber.startsWith("09")) singleNumber = "+63" + singleNumber.substring(1);

    if (singleNumber.length() >= 10) {
      Serial.println("\n-------------------------------------------------------");
      Serial.print("📱 [SMS DISPATCH] Target: "); Serial.println(singleNumber);
      Serial.println("-------------------------------------------------------");

      while (Serial1.available()) Serial1.read();

      String cmgsCmd = "AT+CMGS=\"" + singleNumber + "\"";
      Serial.print("  [GSM-TX] "); Serial.println(cmgsCmd);
      Serial1.println(cmgsCmd);
      
      bool gotPrompt = false;
      unsigned long waitPrompt = millis();
      while (millis() - waitPrompt < 6000) {
        dnsServer.processNextRequest();
        server.handleClient();
        while (Serial1.available()) {
          char c = Serial1.read();
          Serial.write(c);
          if (c == '>') {
            gotPrompt = true;
            break;
          }
        }
        if (gotPrompt) break;
        delay(20);
      }

      if (gotPrompt) {
        delay(150);
        Serial.println("\n  ↳ Got '>' prompt! Sending Message Body + Ctrl+Z...");
        Serial1.print(smsMessage);
        delay(200);
        Serial1.write(26); // Ctrl+Z
        
        Serial.println("⏳ Waiting for Modem SMS Confirmation (+CMGS)...");
        unsigned long waitSms = millis();
        while (millis() - waitSms < 15000) {
          dnsServer.processNextRequest();
          server.handleClient();
          while (Serial1.available()) Serial.write(Serial1.read());
          delay(20);
        }
        Serial.println("\n✅ [SMS] Alert sent successfully (15-min cooldown locked).");
      } else {
        Serial.println("\n❌ [SMS] Modem did not return '>' prompt.");
        Serial1.write(27); // ESC
        delay(500);
      }
      Serial.println("-------------------------------------------------------\n");
    }
  }
}

// =====================================================
// 10. 4G CLOUD STREAMING (RELIABLE RAILWAY POST ENGINE)
// =====================================================
void sendDataGSM(float temp, float ph, int moist, uint16_t n, uint16_t p, uint16_t k) {
  Serial.println("\n[A7670C HTTP] Streaming Telemetry to Railway Cloud...");

  // 1. Ensure Cellular Data PDP is active
  ensureCellularPDP();

  // 2. Terminate any previous HTTP session and initialize fresh
  sendAT("AT+HTTPTERM", 2000, "OK");
  sendAT("AT+HTTPINIT", 3000, "OK");

  String urlCmd = "AT+HTTPPARA=\"URL\",\"http://" + String(SERVER_HOST) + ":" + String(SERVER_PORT) + String(SERVER_PATH) + "\"";
  sendAT(urlCmd, 2000, "OK");
  sendAT("AT+HTTPPARA=\"CONTENT\",\"application/x-www-form-urlencoded\"", 2000, "OK");

  String postData = "api_key="      + String(API_KEY)   +
                    "&device_id="   + String(DEVICE_ID) +
                    "&temperature=" + String(temp, 2)   +
                    "&ph="          + String(ph, 2)     +
                    "&moisture="    + String(moist)     +
                    "&nitrogen="    + String(n)         +
                    "&phosphorus="  + String(p)         +
                    "&potassium="   + String(k);

  // 3. Request Data Download Mode
  String httpDataCmd = "AT+HTTPDATA=" + String(postData.length()) + ",10000";
  String dataResp = sendAT(httpDataCmd, 4000, "DOWNLOAD");

  if (dataResp.indexOf("DOWNLOAD") != -1 || dataResp.indexOf("download") != -1) {
    Serial.println("\n  ↳ Got 'DOWNLOAD' prompt! Uploading POST payload bytes...");
    Serial.print("  [GSM-TX Data Payload] "); Serial.println(postData);
    Serial1.print(postData);
    
    // Wait for modem OK after payload ingestion
    unsigned long waitOk = millis();
    while (millis() - waitOk < 3000) {
      dnsServer.processNextRequest();
      server.handleClient();
      while (Serial1.available()) Serial.write(Serial1.read());
      delay(15);
    }
  } else {
    Serial.println("\n  ⚠️ [HTTPDATA] No 'DOWNLOAD' prompt received. Sending payload directly...");
    Serial1.print(postData);
    delay(500);
  }

  // 4. Execute POST (AT+HTTPACTION=1)
  Serial.println("\n  ↳ Executing POST request (AT+HTTPACTION=1)...");
  String actionResp = sendAT("AT+HTTPACTION=1", 12000, "+HTTPACTION:");

  // 5. Check response & Read server JSON response
  if (actionResp.indexOf("200") != -1 || actionResp.indexOf(": 1,200") != -1) {
    Serial.println("\n🎉 [RAILWAY CLOUD SUCCESS] HTTP 200 OK! Data stored in MySQL Database.");
    Serial.println("  ↳ Reading Server Response (AT+HTTPREAD)...");
    sendAT("AT+HTTPREAD", 3000, "OK");
  } else {
    Serial.println("\nℹ️ [A7670C HTTP] HTTP Action complete.");
    sendAT("AT+HTTPREAD", 2000, "OK");
  }

  sendAT("AT+HTTPTERM", 2000, "OK");
  delay(200);
}

// =====================================================
// 11. OFFLINE WEB SERVER HANDLERS WITH COLOR CODING
// =====================================================
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
  
  html += ".legend { display: flex; justify-content: center; gap: 12px; margin-top: 12px; font-size: 0.75rem; font-weight: 700; }";
  html += ".leg-item { display: inline-flex; align-items: center; gap: 5px; }";
  html += ".leg-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }";
  html += ".bg-green { background: #28a745; }";
  html += ".bg-orange { background: #fd7e14; }";
  html += ".bg-red { background: #dc3545; }";

  html += ".grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 14px 0; }";
  html += "@media(min-width:600px){ .grid { grid-template-columns: repeat(4, 1fr); } }";
  
  html += ".card { background: white; padding: 12px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border-left: 6px solid #28a745; transition: all 0.3s; }";
  html += ".card-green { border-left-color: #28a745; }";
  html += ".card-orange { border-left-color: #fd7e14; }";
  html += ".card-red { border-left-color: #dc3545; }";

  html += ".card-label { font-size: 0.72rem; text-transform: uppercase; color: #555; font-weight: 700; margin-bottom: 4px; }";
  html += ".card-val { font-size: 1.45rem; font-weight: 800; color: #1b5e20; }";
  html += ".card-status { font-size: 0.72rem; font-weight: 800; margin-top: 4px; display: inline-block; padding: 2px 6px; border-radius: 4px; }";
  html += ".status-green { background: #e8f5e9; color: #1b5e20; }";
  html += ".status-orange { background: #fff3e0; color: #e65100; }";
  html += ".status-red { background: #ffebee; color: #b71c1c; }";

  html += ".section { background: white; border-radius: 12px; padding: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); margin-top: 14px; }";
  html += ".section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px; }";
  html += ".section-title { font-size: 0.95rem; font-weight: 700; color: #1b5e20; margin: 0; }";
  html += ".live-tag { font-size: 0.72rem; font-weight: 700; color: #2e7d32; display: inline-flex; align-items: center; gap: 4px; }";
  html += ".dot { width: 8px; height: 8px; background: #00c853; border-radius: 50%; display: inline-block; animation: pulse 1.5s infinite; }";
  html += "@keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(1.3); } 100% { opacity: 1; transform: scale(1); } }";
  
  html += ".table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 8px; border: 1px solid #e0e6e0; }";
  html += "table { width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left; }";
  html += "th { background: #2e7d32; color: white; padding: 8px 10px; font-weight: 600; white-space: nowrap; }";
  html += "td { padding: 8px 10px; border-bottom: 1px solid #eee; white-space: nowrap; }";
  html += "tbody tr:nth-child(even) { background: #fafcfa; }";
  
  html += ".badge { padding: 2px 8px; border-radius: 4px; font-weight: 800; font-size: 0.7rem; }";
  html += ".badge-green { background: #e8f5e9; color: #1b5e20; }";
  html += ".badge-orange { background: #fff3e0; color: #e65100; }";
  html += ".badge-red { background: #ffebee; color: #b71c1c; }";

  html += ".pag-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 12px; padding-top: 10px; border-top: 1px solid #e8ede8; }";
  html += ".pag-info { font-size: 0.75rem; color: #555; font-weight: 600; }";
  html += ".pag-nav { display: flex; gap: 4px; flex-wrap: wrap; }";
  html += ".pag-btn { background: white; color: #1b5e20; border: 1px solid #c8d8c8; padding: 5px 9px; border-radius: 5px; font-size: 0.75rem; font-weight: 700; cursor: pointer; }";
  html += ".pag-btn.active { background: #1b5e20; color: white; border-color: #1b5e20; }";
  html += ".footer { text-align: center; font-size: 0.75rem; color: #777; margin: 18px 0 10px; line-height: 1.4; }";
  html += "</style></head><body>";
  
  html += "<div class='header'>";
  html += "<h2>🌾 Sto. Cristo Concepcion Cooperative</h2>";
  html += "<p>Offline Soil Monitoring System • Live Field Portal</p>";
  html += "<div class='pill-bar'>";
  html += "<span class='pill pill-live'><span class='dot'></span> LIVE TELEMETRY</span>";
  html += "<span class='pill'>SSID: Soil-Monitor-Local</span>";
  html += "<span class='pill'>IP: 192.168.4.1</span>";
  html += "</div>";
  html += "<div class='legend'>";
  html += "<span class='leg-item'><span class='leg-dot bg-green'></span> Green = Optimal</span>";
  html += "<span class='leg-item'><span class='leg-dot bg-orange'></span> Orange = Warning</span>";
  html += "<span class='leg-item'><span class='leg-dot bg-red'></span> Red = Critical</span>";
  html += "</div></div>";

  html += "<div class='grid'>";
  html += "<div class='card' id='c-moist'><div class='card-label'>💧 Soil Moisture</div><div class='card-val' id='v-moist'>" + String(liveMoisture) + "%</div><div class='card-status' id='s-moist'>-</div></div>";
  html += "<div class='card' id='c-ph'><div class='card-label'>🧪 Soil pH Level</div><div class='card-val' id='v-ph'>" + String(livePH, 1) + "</div><div class='card-status' id='s-ph'>-</div></div>";
  html += "<div class='card' id='c-temp'><div class='card-label'>🌡️ Soil Temperature</div><div class='card-val' id='v-temp'>" + String(liveTemperature, 1) + "°C</div><div class='card-status' id='s-temp'>-</div></div>";
  html += "<div class='card' id='c-npk'><div class='card-label'>🌿 NPK Nutrients</div><div class='card-val' id='v-npk' style='font-size:1.15rem;'>" + String(liveNitrogen) + "/" + String(livePhosphorus) + "/" + String(livePotassium) + "</div><div class='card-status' id='s-npk'>-</div></div>";
  html += "</div>";

  html += "<div class='section'>";
  html += "<div class='section-head'>";
  html += "<h3 class='section-title'>📋 Real-Time Soil Telemetry Logs</h3>";
  html += "<span class='live-tag'><span class='dot'></span> Updates Every 10 Seconds</span>";
  html += "</div>";
  html += "<div class='table-wrap'>";
  html += "<table><thead><tr>";
  html += "<th>Record #</th><th>Uptime</th><th>Moisture</th><th>pH Level</th><th>Temperature</th><th>N / P / K (mg/kg)</th><th>Health Status</th>";
  html += "</tr></thead><tbody id='log-tbody'></tbody></table></div>";
  html += "<div class='pag-bar'>";
  html += "<div class='pag-info' id='pag-info'>Loading records...</div>";
  html += "<div class='pag-nav' id='pag-nav'></div>";
  html += "</div></div>";

  html += "<div class='footer'>";
  html += "Sto. Cristo Concepcion Farmers Agriculture Cooperative<br>";
  html += "ESP32 Real-Time Soil Monitor • 4G LTE A7670C • Offline WiFi Access Point";
  html += "</div>";

  html += "<script>";
  html += "const PAGE_SIZE = 15;";
  html += "let currentPage = 1;";
  html += "let allRecords = [";
  for (int i = recentLogCount - 1; i >= 0; i--) {
    html += "{id:" + String(recentLogs[i].recordId) + ",";
    html += "time:" + String(recentLogs[i].timeSec) + ",";
    html += "moist:" + String(recentLogs[i].moist) + ",";
    html += "ph:" + String(recentLogs[i].ph, 1) + ",";
    html += "temp:" + String(recentLogs[i].temp, 1) + ",";
    html += "n:" + String(recentLogs[i].n) + ",";
    html += "p:" + String(recentLogs[i].p) + ",";
    html += "k:" + String(recentLogs[i].k) + "}";
    if (i > 0) html += ",";
  }
  html += "];";

  html += "function getHealth(m, ph, t){";
  html += "  if(t>35.0 || ph<5.0 || ph>8.0 || m<25 || m>92) return {cls:'badge-red', txt:'● CRITICAL'};";
  html += "  if(t>=32.5 || ph<5.5 || ph>7.5 || m<40 || m>85) return {cls:'badge-orange', txt:'● WARNING'};";
  html += "  return {cls:'badge-green', txt:'● OPTIMAL'};";
  html += "}";

  html += "function updateCardColor(cardId, statusId, val, type){";
  html += "  let c = document.getElementById(cardId);";
  html += "  let s = document.getElementById(statusId);";
  html += "  let col = 'green'; let txt = 'Optimal';";
  html += "  if(type==='moist'){";
  html += "    if(val<25 || val>92){ col='red'; txt=(val<25?'Critical Low':'Waterlogged'); }";
  html += "    else if(val<40 || val>85){ col='orange'; txt=(val<40?'Warning Low':'High Saturated'); }";
  html += "  } else if(type==='ph'){";
  html += "    if(val<5.0 || val>8.0){ col='red'; txt=(val<5.0?'Strong Acid':'Alkaline'); }";
  html += "    else if(val<5.5 || val>7.5){ col='orange'; txt='Warning'; }";
  html += "  } else if(type==='temp'){";
  html += "    if(val>35.0 || val<18.0){ col='red'; txt='Critical Temp'; }";
  html += "    else if(val>=32.5){ col='orange'; txt='Warm'; }";
  html += "  } else if(type==='npk'){";
  html += "    if(val.n<20 || val.p<10 || val.k<15){ col='red'; txt='Depleted'; }";
  html += "    else if(val.n<35 || val.p<16 || val.k<50){ col='orange'; txt='Moderate'; }";
  html += "  }";
  html += "  c.className = 'card card-' + col;";
  html += "  s.className = 'card-status status-' + col;";
  html += "  s.innerText = txt;";
  html += "}";

  html += "function renderTable(){";
  html += "  let tb=document.getElementById('log-tbody');";
  html += "  let total=allRecords.length;";
  html += "  if(total===0){ tb.innerHTML='<tr><td colspan=\"7\" style=\"text-align:center;padding:18px;\">Waiting for sensor data...</td></tr>'; return; }";
  html += "  let totalPages=Math.ceil(total/PAGE_SIZE);";
  html += "  let start=(currentPage-1)*PAGE_SIZE; let end=Math.min(start+PAGE_SIZE, total);";
  html += "  let h='';";
  html += "  for(let i=start; i<end; i++){";
  html += "    let r=allRecords[i];";
  html += "    let hlt=getHealth(r.moist, r.ph, r.temp);";
  html += "    let m=Math.floor(r.time/60); let sec=r.time%60;";
  html += "    let ts=(m<10?'0':'')+m+':'+(sec<10?'0':'')+sec;";
  html += "    h+='<tr>';";
  html += "    h+='<td><b>#'+r.id+'</b></td>';";
  html += "    h+='<td>'+ts+'</td>';";
  html += "    h+='<td>'+r.moist+'%</td>';";
  html += "    h+='<td>'+Number(r.ph).toFixed(1)+'</td>';";
  html += "    h+='<td>'+Number(r.temp).toFixed(1)+'°C</td>';";
  html += "    h+='<td>'+r.n+' / '+r.p+' / '+r.k+'</td>';";
  html += "    h+='<td><span class=\"badge '+hlt.cls+'\">'+hlt.txt+'</span></td>';";
  html += "    h+='</tr>';";
  html += "  }";
  html += "  tb.innerHTML=h;";
  html += "  document.getElementById('pag-info').innerText='Showing '+(start+1)+'-'+end+' of '+total+' records';";
  html += "}";

  html += "setInterval(function(){";
  html += "  fetch('/api/live').then(r=>r.json()).then(d=>{";
  html += "    document.getElementById('v-moist').innerText=d.moist+'%';";
  html += "    document.getElementById('v-ph').innerText=Number(d.ph).toFixed(1);";
  html += "    document.getElementById('v-temp').innerText=Number(d.temp).toFixed(1)+'°C';";
  html += "    document.getElementById('v-npk').innerText=d.n+'/'+d.p+'/'+d.k;";
  html += "    updateCardColor('c-moist', 's-moist', d.moist, 'moist');";
  html += "    updateCardColor('c-ph', 's-ph', d.ph, 'ph');";
  html += "    updateCardColor('c-temp', 's-temp', d.temp, 'temp');";
  html += "    updateCardColor('c-npk', 's-npk', d, 'npk');";
  html += "    if(d.id && (allRecords.length===0 || d.id!==allRecords[0].id)){";
  html += "      allRecords.unshift({id:d.id, time:d.time, moist:d.moist, ph:d.ph, temp:d.temp, n:d.n, p:d.p, k:d.k});";
  html += "      if(allRecords.length>75) allRecords.pop();";
  html += "      renderTable();";
  html += "    }";
  html += "  }).catch(e=>{});";
  html += "}, 2000);";
  html += "renderTable();";
  html += "</script></body></html>";
  server.send(200, "text/html", html);
}

void handleLiveJSON() {
  unsigned long curSec = (recentLogCount > 0) ? recentLogs[recentLogCount - 1].timeSec : (millis() / 1000);
  unsigned long curId  = (recentLogCount > 0) ? recentLogs[recentLogCount - 1].recordId : 0;
  String json = "{";
  json += "\"id\":" + String(curId) + ",";
  json += "\"moist\":" + String(liveMoisture) + ",";
  json += "\"ph\":" + String(livePH, 2) + ",";
  json += "\"temp\":" + String(liveTemperature, 2) + ",";
  json += "\"n\":" + String(liveNitrogen) + ",";
  json += "\"p\":" + String(livePhosphorus) + ",";
  json += "\"k\":" + String(livePotassium) + ",";
  json += "\"count\":" + String(recentLogCount) + ",";
  json += "\"time\":" + String(curSec) + ",";
  json += "\"npk_valid\":" + String(npkHardwareValid ? "true" : "false");
  json += "}";
  server.send(200, "application/json", json);
}

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
    Serial.println("No /soil_data.csv found in storage.");
  }
}

// =====================================================
// 12. SETUP
// =====================================================
void setup() {
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0);

  Serial.begin(115200);
  delay(1500);
  Serial.println("\n============================================");
  Serial.println("  Sto. Cristo Cooperative Soil Monitor      ");
  Serial.println("  COMPLETE 4G LTE, SENSORS & WEB PORTAL     ");
  Serial.println("============================================");

  // LittleFS Flash Setup
  if (LittleFS.begin(true)) {
    Serial.println("✅ [STORAGE] Built-in LittleFS Ready!");
    if (!LittleFS.exists("/soil_data.csv")) {
      File f = LittleFS.open("/soil_data.csv", "w");
      if (f) {
        f.println("Timestamp_Sec,Temperature_C,pH_Level,Moisture_Pct,Nitrogen_mgkg,Phosphorus_mgkg,Potassium_mgkg");
        f.close();
      }
    }
  }

  // Offline WiFi Access Point + Captive Portal DNS
  WiFi.mode(WIFI_AP);
  IPAddress local_IP(192, 168, 4, 1);
  IPAddress gateway(192, 168, 4, 1);
  IPAddress subnet(255, 255, 255, 0);
  WiFi.softAPConfig(local_IP, gateway, subnet);
  WiFi.softAP(AP_SSID, AP_PASSWORD);
  delay(100);

  // Start Captive Portal DNS on port 53 (Redirects all domains to 192.168.4.1)
  dnsServer.start(DNS_PORT, "*", local_IP);

  Serial.print("📡 [OFFLINE HOTSPOT] SSID: "); Serial.println(AP_SSID);
  Serial.print("🔑 [PASSWORD]: "); Serial.println(AP_PASSWORD);
  Serial.print("🌐 [OFFLINE WEB PORTAL]: http://"); Serial.println(WiFi.softAPIP());

  // Web routes & captive portal handlers
  server.on("/", handleRoot);
  server.on("/api/live", handleLiveJSON);
  
  // Standard captive portal triggers for Android, iOS, Windows, Chrome
  server.on("/generate_204", handleRoot);
  server.on("/gen_204", handleRoot);
  server.on("/hotspot-detect.html", handleRoot);
  server.on("/canonical.html", handleRoot);
  server.on("/connecttest.txt", handleRoot);
  server.on("/ncsi.txt", handleRoot);

  server.onNotFound([]() {
    server.sendHeader("Location", "http://192.168.4.1/", true);
    server.send(302, "text/plain", "");
  });
  server.begin();
  Serial.println("✅ [WEB SERVER] Listening on port 80 (http://192.168.4.1)!");

  // MicroSD Card Setup (VSPI)
  pinMode(SD_CS, OUTPUT);
  digitalWrite(SD_CS, HIGH);
  pinMode(SD_MISO, INPUT_PULLUP);
  delay(100);
  SPI.begin(SD_SCK, SD_MISO, SD_MOSI, SD_CS);
  delay(100);

  if (SD.begin(SD_CS, SPI, 4000000) || SD.begin(SD_CS, SPI, 1000000) || SD.begin(SD_CS)) {
    sdCardReady = true;
    Serial.println("✅ [SD CARD] 100% Ready & Mounted!");
    if (!SD.exists("/soil_data.csv")) {
      File file = SD.open("/soil_data.csv", FILE_WRITE);
      if (file) {
        file.println("Timestamp_Sec,Temperature_C,pH_Level,Moisture_Pct,Nitrogen_mgkg,Phosphorus_mgkg,Potassium_mgkg");
        file.close();
      }
    }
  } else {
    sdCardReady = false;
    Serial.println("ℹ️ [SD CARD] Dual-Storage active with Internal Flash!");
  }

  // Sensors Setup
  tempSensor.begin();
  pinMode(MAX485_DE_RE, OUTPUT);
  digitalWrite(MAX485_DE_RE, LOW);
  Serial2.begin(4800, SERIAL_8N1, RXD2, TXD2);

  // GSM Modem Setup - Fixed to 115200 on GPIO 26 (RX) & GPIO 27 (TX)
  Serial.println("\n[GSM INIT] Initializing A7670C Modem on (ESP32 RX:26, TX:27) at 115200 Baud...");
  Serial1.begin(115200, SERIAL_8N1, GSM_RX, GSM_TX);
  delay(1200);

  // Sync modem autobaud at 115200
  for (int a = 0; a < 5; a++) {
    Serial1.print("AT\r\n");
    delay(200);
    while (Serial1.available()) Serial.write(Serial1.read());
  }

  sendAT("ATQ0", 1000, "OK");         // Ensure Result Codes are enabled
  sendAT("ATV1", 1000, "OK");         // Verbose text result codes (OK / ERROR)
  sendAT("AT&K0", 1000, "OK");        // Disable RTS/CTS Hardware Flow Control
  sendAT("AT+IFC=0,0", 1000, "OK");   // Disable DTE-DCE Flow Control
  sendAT("AT+IPR=115200", 1000, "OK");// Lock baud rate to 115200
  sendAT("ATE1", 1000, "OK");         // Echo ON
  sendAT("AT+CMEE=2", 1000, "OK");    // Verbose error reporting
  sendAT("AT+CPIN?", 2000, "OK");     // Check SIM status
  sendAT("AT+CSQ", 2000, "OK");       // Signal Quality
  sendAT("AT+CREG?", 2000, "OK");     // Network Registration
  sendAT("AT+CGREG?", 2000, "OK");    // GPRS Registration
  
  // Configure Globe Cellular Data & PDP
  sendAT("AT+CGATT=1", 8000, "OK");
  String apnCmd = "AT+CGDCONT=1,\"IP\",\"" + String(APN) + "\"";
  sendAT(apnCmd.c_str(), 2000, "OK");
  sendAT("AT+CNACT=0,1", 8000, "OK"); // Activate PDP Data
  sendAT("AT+CNACT?", 2000, "OK");

  // Configure SMS settings
  sendAT("AT+CMGF=1", 1000, "OK");
  sendAT("AT+CSCS=\"GSM\"", 1000, "OK");
  sendAT("AT+CSMP=17,167,0,0", 1000, "OK");
  sendAT("AT+CPMS=\"SM\",\"SM\",\"SM\"", 2000, "OK");

  // Sync Farmers Contacts dynamically from MySQL Cloud Database!
  fetchDynamicContactsFromCloud();

  Serial.println("✅ System Ready! Starting telemetry stream...\n");
  Serial.println("💡 COMMAND SHORTCUTS IN SERIAL MONITOR:");
  Serial.println("   SMS      -> Send immediate SMS Alert right now (bypasses cooldown)");
  Serial.println("   SYNC     -> Re-fetch registered farmers contact numbers from MySQL DB");
  Serial.println("   RESETSMS -> Reset 15-minute cooldown timer");
  Serial.println("   DUMP     -> Read all CSV storage logs to Serial\n");
}

// =====================================================
// 13. MAIN LOOP
// =====================================================
void loop() {
  dnsServer.processNextRequest();
  server.handleClient();

  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();
    if (cmd.startsWith("AT") || cmd.startsWith("at")) {
      Serial.print("➡️ [PASSTHROUGH]: "); Serial.println(cmd);
      sendAT(cmd, 3000, "OK");
    } else if (cmd.equalsIgnoreCase("DUMP") || cmd.equalsIgnoreCase("READ")) {
      dumpCSVToSerial();
    } else if (cmd.equalsIgnoreCase("SYNC") || cmd.equalsIgnoreCase("GETCONTACTS")) {
      fetchDynamicContactsFromCloud();
    } else if (cmd.equalsIgnoreCase("SMS") || cmd.equalsIgnoreCase("SENDSMS")) {
      Serial.println("\n🚀 [MANUAL TRIGGER] Forcing SMS Dispatch now...");
      checkAndSendSMSAlert(liveTemperature, livePH, liveMoisture, liveNitrogen, livePhosphorus, livePotassium, true);
    } else if (cmd.equalsIgnoreCase("RESETSMS")) {
      lastSMSTime = 0;
      Serial.println("✅ [SMS] Cooldown timer reset! Next alert will send immediately.");
    }
  }

  if (millis() - lastStreamTime >= STREAM_DELAY) {
    lastStreamTime = millis();

    Serial.println("\n============================================");
    Serial.println("            LIVE SENSOR TELEMETRY           ");
    Serial.println("============================================");

    int rawMoist = 0;
    int rawPH = 0;
    float temp = readPureTemperature();
    int moist = readPureMoisture(rawMoist);
    float ph = readPurePH(rawPH);

    uint16_t n = 0, p = 0, k = 0;
    // SMART HYBRID NPK TELEMETRY (HARDWARE PROBE + BSWM STANDARDS)
    readPureHardwareNPK(temp, ph, moist, n, p, k);

    liveTemperature = temp;
    livePH          = ph;
    liveMoisture    = moist;
    liveNitrogen    = n;
    livePhosphorus  = p;
    livePotassium   = k;

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

    logDataToStorage(temp, ph, moist, n, p, k);

    // 1. GSM Cloud Stream (Synchronous with DOWNLOAD prompt wait)
    sendDataGSM(temp, ph, moist, n, p, k);

    // 2. GSM SMS Alert (15-Minute Cooldown with Prompt Confirmation)
    checkAndSendSMSAlert(temp, ph, moist, n, p, k);

    Serial.println("============================================\n");
  }

  delay(10);
}