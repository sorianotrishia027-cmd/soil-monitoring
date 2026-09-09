#include <OneWire.h>
#include <DallasTemperature.h>
#include "soc/soc.h"
#include "soc/rtc_cntl_reg.h"

// ─────────────────────────────────────────────
// INTERVAL CONFIGURATION
// ─────────────────────────────────────────────
const unsigned long POST_INTERVAL = 30000; // 30 seconds between readings (was 2s)

// ─────────────────────────────────────────────
// PIN DEFINITIONS
// ─────────────────────────────────────────────
#define ONE_WIRE_BUS   22   // DS18B20 Temperature Sensor
#define PH_PIN         35   // Analog pH Sensor
#define SOIL_PIN       34   // Analog Soil Moisture Sensor
#define RXD2           16   // NPK Sensor RX (Connects to MAX485 RO)
#define TXD2           17   // NPK Sensor TX (Connects to MAX485 DI)
#define MAX485_DE_RE   21   // MAX485 DE and RE control pin

// GSM / 4G Module (UART1)
#define RXD1           26   // Connect to GSM Module TX
#define TXD1           27   // Connect to GSM Module RX

// ─────────────────────────────────────────────
// SERVER / API CONFIGURATION
// ─────────────────────────────────────────────
const char* serverHost = "soil-monitoring-production.up.railway.app";
const char* serverPath = "/api/store_data.php";
const char* apiKey     = "SCC_AGRI_SECRET_KEY_2026";
const char* deviceId   = "ESP32_GSM_01";

// ─────────────────────────────────────────────
// SMS CONFIGURATION
// ─────────────────────────────────────────────
const char* targetPhone = "09924996572";

// ─────────────────────────────────────────────
// APN CONFIGURATION — Change to match your SIM:
//   Globe/TM  → "internet.globe.com.ph"
//   Smart/TNT → "internet" or "smartlte"
//   DITO      → "internet.dito.ph"
// ─────────────────────────────────────────────
const char* simAPN = "internet.globe.com.ph";

// ─────────────────────────────────────────────
// SENSOR OBJECTS
// ─────────────────────────────────────────────
OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

// ─────────────────────────────────────────────
// CRC16 for Modbus NPK Sensor
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
// READ NPK SENSOR via RS485/Modbus RTU
// ─────────────────────────────────────────────
bool readNPKSensor(uint16_t &n, uint16_t &p, uint16_t &k) {
  const long    TARGET_BAUD  = 4800;
  const uint8_t TARGET_ID    = 0x01;
  const uint16_t START_REG   = 0x0000;
  const uint8_t NUM_REGS     = 3;

  Serial2.begin(TARGET_BAUD, SERIAL_8N1, RXD2, TXD2);
  delay(20);

  // Flush any leftover bytes
  while (Serial2.available()) Serial2.read();

  // Enable transmit mode on MAX485
  digitalWrite(MAX485_DE_RE, HIGH);
  delayMicroseconds(200);

  // Build Modbus RTU request packet
  uint8_t pkt[] = {
    TARGET_ID, 0x03,
    highByte(START_REG), lowByte(START_REG),
    highByte(NUM_REGS),  lowByte(NUM_REGS)
  };
  uint16_t crc = calculateCRC(pkt, 6);
  uint8_t sendPkt[8];
  memcpy(sendPkt, pkt, 6);
  sendPkt[6] = lowByte(crc);
  sendPkt[7] = highByte(crc);

  Serial2.write(sendPkt, 8);
  Serial2.flush();

  delayMicroseconds(200);
  // Switch MAX485 to receive mode
  digitalWrite(MAX485_DE_RE, LOW);

  // Wait up to 400ms for response
  unsigned long t   = millis();
  int           idx = 0;
  uint8_t       buf[32];

  while (millis() - t < 400) {
    if (Serial2.available() && idx < 32) {
      buf[idx++] = Serial2.read();
    }
  }

  // Validate response: [ID][0x03][byte_count][N_H][N_L][P_H][P_L][K_H][K_L][CRC_L][CRC_H]
  if (idx >= 9 && buf[0] == TARGET_ID && buf[1] == 0x03) {
    n = (buf[3] << 8) | buf[4];
    p = (buf[5] << 8) | buf[6];
    k = (buf[7] << 8) | buf[8];
    return true;
  }

  return false;
}

// ─────────────────────────────────────────────
// FLUSH GSM SERIAL BUFFER & PRINT TO SERIAL MONITOR
// ─────────────────────────────────────────────
void flushGSMResponse(unsigned long waitMs = 1500) {
  unsigned long start = millis();
  while (millis() - start < waitMs) {
    while (Serial1.available()) {
      Serial.write(Serial1.read());
    }
  }
  Serial.println();
}

// ─────────────────────────────────────────────
// SEND SMS NOTIFICATION
// ─────────────────────────────────────────────
void sendSMS(String message) {
  Serial.println("\n[SMS] Sending SMS...");

  Serial1.println("AT+CMGF=1");         // Set text mode
  delay(500);
  flushGSMResponse(500);

  Serial1.print("AT+CMGS=\"");
  Serial1.print(targetPhone);
  Serial1.println("\"\r");
  delay(1500);

  Serial1.print(message);
  delay(500);
  Serial1.write(26);                    // CTRL+Z — triggers send
  delay(5000);

  flushGSMResponse(2000);
  Serial.println("[SMS] Done.");
}

// ─────────────────────────────────────────────
// SEND SENSOR DATA TO SERVER VIA HTTPS POST
// ─────────────────────────────────────────────
void sendDataGSM(float temp, float ph, int moist, uint16_t n, uint16_t p, uint16_t k) {
  Serial.println("\n[HTTP] Sending data to server via HTTPS...");

  // Step 1: Initialize HTTP stack
  Serial1.println("AT+HTTPINIT");
  delay(1000);
  flushGSMResponse(500);

  // Step 2: Enable SSL/TLS for HTTPS (Railway requires HTTPS)
  Serial1.println("AT+HTTPSSL=1");
  delay(500);
  flushGSMResponse(500);

  // Step 3: Set bearer profile (GPRS context)
  Serial1.println("AT+HTTPPARA=\"CID\",1");
  delay(500);
  flushGSMResponse(500);

  // Step 4: Set target URL (HTTPS)
  String url = "https://" + String(serverHost) + String(serverPath);
  Serial.print("[HTTP] URL: ");
  Serial.println(url);
  Serial1.print("AT+HTTPPARA=\"URL\",\"");
  Serial1.print(url);
  Serial1.println("\"");
  delay(1000);
  flushGSMResponse(500);

  // Step 5: Set Content-Type header
  Serial1.println("AT+HTTPPARA=\"CONTENT\",\"application/x-www-form-urlencoded\"");
  delay(500);
  flushGSMResponse(500);

  // Step 6: Build POST body
  String postData = "api_key="     + String(apiKey)      +
                    "&device_id="  + String(deviceId)     +
                    "&temperature="+ String(temp, 2)      +
                    "&ph="         + String(ph, 2)        +
                    "&moisture="   + String(moist)        +
                    "&nitrogen="   + String(n)            +
                    "&phosphorus=" + String(p)            +
                    "&potassium="  + String(k);

  Serial.print("[HTTP] POST body: ");
  Serial.println(postData);

  // Step 7: Tell module how many bytes to expect, with 10s input timeout
  Serial1.print("AT+HTTPDATA=");
  Serial1.print(postData.length());
  Serial1.println(",10000");
  delay(2000);
  flushGSMResponse(500);

  // Step 8: Send the POST body data
  Serial1.print(postData);
  delay(3000);

  // Step 9: Execute POST request (1 = POST)
  Serial1.println("AT+HTTPACTION=1");
  delay(8000);                          // Extra time for HTTPS TLS handshake
  flushGSMResponse(1000);

  // Step 10: Read server response body
  Serial.print("[HTTP] Server Response: ");
  Serial1.println("AT+HTTPREAD");
  delay(2000);
  flushGSMResponse(3000);              // Prints JSON response to Serial Monitor

  // Step 11: Terminate HTTP session
  Serial1.println("AT+HTTPTERM");
  delay(1000);
  flushGSMResponse(500);

  Serial.println("[HTTP] HTTPS transmission finished.");
}

// ─────────────────────────────────────────────
// SETUP
// ─────────────────────────────────────────────
void setup() {
  WRITE_PERI_REG(RTC_CNTL_BROWN_OUT_REG, 0); // Disable brownout detector

  Serial.begin(115200);
  delay(1000);
  Serial.println("============================================");
  Serial.println("  Sto. Cristo Cooperative Soil Monitor");
  Serial.println("  ESP32 + GSM Mode — System Starting...");
  Serial.println("============================================");

  // Initialize DS18B20 temperature sensor
  sensors.begin();

  // Set MAX485 control pin
  pinMode(MAX485_DE_RE, OUTPUT);
  digitalWrite(MAX485_DE_RE, LOW);

  // Initialize GSM module on UART1
  Serial1.begin(9600, SERIAL_8N1, RXD1, TXD1);
  delay(2000);

  Serial.println("[GSM] Initializing GSM module...");

  // Basic AT handshake
  Serial1.println("AT");
  delay(1000);
  flushGSMResponse(500);

  // Echo off (cleaner Serial Monitor output)
  Serial1.println("ATE0");
  delay(500);
  flushGSMResponse(500);

  // Set SMS text mode
  Serial1.println("AT+CMGF=1");
  delay(500);
  flushGSMResponse(500);

  // ── GPRS / Bearer Setup ──────────────────────
  Serial.println("[GSM] Configuring GPRS bearer...");

  Serial1.println("AT+SAPBR=3,1,\"CONTYPE\",\"GPRS\"");
  delay(1000);
  flushGSMResponse(500);

  // Set APN — adjust simAPN constant at top of file for your SIM carrier
  Serial1.print("AT+SAPBR=3,1,\"APN\",\"");
  Serial1.print(simAPN);
  Serial1.println("\"");
  delay(1000);
  flushGSMResponse(500);

  // Open bearer (attach to GPRS)
  Serial1.println("AT+SAPBR=1,1");
  delay(5000);                          // Wait up to 5s for GPRS attach
  flushGSMResponse(1000);

  // Confirm bearer IP was assigned
  Serial.println("[GSM] Checking GPRS IP address...");
  Serial1.println("AT+SAPBR=2,1");     // Should print: +SAPBR: 1,1,"10.x.x.x"
  delay(2000);
  flushGSMResponse(1000);

  // ── Startup SMS ──────────────────────────────
  Serial.println("[SMS] Sending startup notification...");
  Serial1.println("AT+CMGF=1");
  delay(500);
  Serial1.print("AT+CMGS=\"");
  Serial1.print(targetPhone);
  Serial1.println("\"\r");
  delay(1500);
  Serial1.print("Sto. Cristo Cooperative Soil Monitor is ONLINE. Device: ESP32_GSM_01");
  delay(500);
  Serial1.write(26); // CTRL+Z
  delay(5000);
  flushGSMResponse(1000);

  Serial.println("[SETUP] Initialization complete. Starting sensor loop...");
}

// ─────────────────────────────────────────────
// MAIN LOOP
// ─────────────────────────────────────────────
void loop() {
  Serial.println("\n============================================");
  Serial.println("           READING SENSORS");
  Serial.println("============================================");

  // ── Temperature ─────────────────────────────
  sensors.requestTemperatures();
  float temp = sensors.getTempCByIndex(0);
  Serial.print("[TEMP] Temperature : ");
  Serial.print(temp, 2);
  Serial.println(" °C");

  // ── pH ──────────────────────────────────────
  int rawPH = analogRead(PH_PIN);
  float voltagePH = rawPH * 3.3 / 4095.0;
  float ph = 7.0 + ((2.5 - voltagePH) * 1.5);
  ph = constrain(ph, 0.0, 14.0);
  Serial.print("[PH]   pH Value    : ");
  Serial.println(ph, 2);

  // ── Soil Moisture ───────────────────────────
  int rawMoist = analogRead(SOIL_PIN);
  int moist = 0;
  if (rawMoist > 10) {
    moist = constrain(map(rawMoist, 4095, 0, 0, 100), 0, 100);
  }
  Serial.print("[MOIST] Moisture   : ");
  Serial.print(moist);
  Serial.print("% (raw=");
  Serial.print(rawMoist);
  Serial.println(")");

  // ── NPK (Nitrogen, Phosphorus, Potassium) ───
  uint16_t n = 0, p = 0, k = 0;
  bool npkOK = readNPKSensor(n, p, k);
  if (npkOK) {
    Serial.print("[NPK]  N:");
    Serial.print(n);
    Serial.print("  P:");
    Serial.print(p);
    Serial.print("  K:");
    Serial.println(k);
  } else {
    n = 0; p = 0; k = 0;
    Serial.println("[NPK]  No response — values set to 0");
  }

  // ── Build SMS Message ────────────────────────
  String smsMessage =
    "Sto. Cristo Soil Report\n"
    "Temp: "  + String(temp, 1)  + "C\n"
    "pH: "    + String(ph, 1)    + "\n"
    "Moist: " + String(moist)    + "%\n"
    "N:"      + String(n)        +
    " P:"     + String(p)        +
    " K:"     + String(k);

  // ── Send SMS ────────────────────────────────
  sendSMS(smsMessage);

  // ── Send to Railway cloud via HTTPS POST ─────
  sendDataGSM(temp, ph, moist, n, p, k);

  Serial.println("============================================");
  Serial.print("Waiting ");
  Serial.print(POST_INTERVAL / 1000);
  Serial.println("s before next reading...");
  delay(POST_INTERVAL);
}
