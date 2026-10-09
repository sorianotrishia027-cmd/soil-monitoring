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
#define ONE_WIRE_BUS   22
#define PH_PIN         35
#define MOISTURE_PIN   34

// MAX485 / RS485 NPK
#define RXD2           16
#define TXD2           17
#define MAX485_DE_RE   21

// MicroSD
#define SD_CS          5
#define SD_SCK         18
#define SD_MISO        19
#define SD_MOSI        23

// GSM A7670C
#define GSM_RX         26
#define GSM_TX         27

// =====================================================
// 3. GSM & CLOUD SERVER CONFIGURATION
// =====================================================
const char* APN          = "internet.globe.com.ph";
const char* SERVER_HOST  = "altaria.proxy.rlwy.net";
const int   SERVER_PORT  = 59755;
const char* SERVER_PATH  = "/api/store_data.php";
const char* API_KEY      = "SCC_AGRI_SECRET_KEY_2026";
const char* DEVICE_ID    = "ESP32_GSM_01";

// =====================================================
// SMS CONTACT CONFIGURATION
// =====================================================

// One hardcoded number intentionally kept for comparison testing.
const char* HARDCODED_TEST_CONTACT = "09128057380";

// Database contacts are stored separately.
// This allows comparison:
// 1. HARDcoded test number
// 2. Database-loaded number(s)
String databaseContacts = "";

const unsigned long SMS_COOLDOWN = 900000UL; // 15 minutes
const unsigned long STREAM_DELAY  = 10000UL; // 10 seconds

unsigned long lastStreamTime = 0;
unsigned long lastSMSTime    = 0;

long activeGsmBaud = 115200;

// =====================================================
// 4. GLOBAL LIVE DATA & LOGS
// =====================================================
float liveTemperature   = 27.5;
float livePH           = 7.0;
int   liveMoisture     = 55;

uint16_t liveNitrogen   = 45;
uint16_t livePhosphorus = 24;
uint16_t livePotassium  = 72;

bool npkHardwareValid = false;
bool sdCardReady      = false;

struct TelemetryLog {
  unsigned long recordId;
  unsigned long timeSec;
  float temp;
  float ph;
  int moist;
  uint16_t n;
  uint16_t p;
  uint16_t k;
};

#define MAX_LOGS 75

TelemetryLog recentLogs[MAX_LOGS];

int recentLogCount = 0;
unsigned long totalReadingCounter = 0;

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature tempSensor(&oneWire);

// =====================================================
// 5. GSM AT COMMAND ENGINE
// =====================================================
void clearGSMInput() {
  while (Serial1.available()) {
    Serial1.read();
  }
}

String sendAT(
  const String& cmd,
  unsigned long waitMs = 500
) {
  Serial.print("  [GSM-TX] ");
  Serial.println(cmd);

  Serial1.println(cmd);

  String resp = "";
  unsigned long start = millis();

  while (millis() - start < waitMs) {
    dnsServer.processNextRequest();
    server.handleClient();

    while (Serial1.available()) {
      char c = Serial1.read();
      Serial.write(c);
      resp += c;
    }

    delay(5);
  }

  if (resp.length() == 0) {
    Serial.println("  [GSM-RX] <NO RESPONSE>");
  }

  return resp;
}

bool gsmAT() {
  Serial.println();
  Serial.println("[GSM TEST] Checking A7670C...");

  String resp = sendAT("AT", 1500);

  if (resp.indexOf("OK") >= 0) {
    Serial.println("✅ [GSM TEST] A7670C responded with OK.");
    return true;
  }

  Serial.println("❌ [GSM TEST] A7670C did not respond to AT.");
  return false;
}

bool gsmATWithRetry() {
  if (gsmAT()) {
    return true;
  }

  Serial.println("⚠️ [GSM TEST] Reinitializing UART and retrying...");

  Serial1.end();
  delay(300);

  Serial1.begin(
    activeGsmBaud,
    SERIAL_8N1,
    GSM_RX,
    GSM_TX
  );

  delay(300);

  return gsmAT();
}

void printGSMNetworkStatus() {
  Serial.println();
  Serial.println("[GSM STATUS] Reading modem/network state...");

  String csq = sendAT("AT+CSQ", 1500);
  Serial.println("[GSM STATUS] CSQ:");
  Serial.println(csq);

  String creg = sendAT("AT+CREG?", 1500);
  Serial.println("[GSM STATUS] CREG:");
  Serial.println(creg);

  String cereg = sendAT("AT+CEREG?", 1500);
  Serial.println("[GSM STATUS] CEREG:");
  Serial.println(cereg);

  String cgreg = sendAT("AT+CGREG?", 1500);
  Serial.println("[GSM STATUS] CGREG:");
  Serial.println(cgreg);

  String cgatt = sendAT("AT+CGATT?", 2000);
  Serial.println("[GSM STATUS] CGATT:");
  Serial.println(cgatt);

  String cna = sendAT("AT+CNACT?", 3000);
  Serial.println("[GSM STATUS] CNACT:");
  Serial.println(cna);

  Serial.println("-------------------------------------------------------");
}

// =====================================================
// WAIT FOR SPECIFIC GSM RESPONSE
// =====================================================
bool waitForGSMToken(
  const char* token,
  unsigned long timeoutMs
) {
  String response = "";

  unsigned long start = millis();

  while (
    millis() - start < timeoutMs
  ) {
    dnsServer.processNextRequest();
    server.handleClient();

    while (
      Serial1.available()
    ) {
      char c = Serial1.read();

      Serial.write(c);

      response += c;

      if (
        response.indexOf(token) >= 0
      ) {
        return true;
      }

      // Catch common GSM errors early.
      if (
        response.indexOf("+CME ERROR") >= 0 ||
        response.indexOf("+CMS ERROR") >= 0 ||
        response.indexOf("\r\nERROR") >= 0
      ) {
        return false;
      }

      if (
        response.length() > 600
      ) {
        response.remove(
          0,
          300
        );
      }
    }

    delay(10);
  }

  return false;
}

// =====================================================
// SEND COMMAND AND WAIT FOR TOKEN
// =====================================================
bool sendATWaitToken(
  const char* cmd,
  const char* token,
  unsigned long timeoutMs
) {
  Serial.print("  [GSM-TX] ");
  Serial.println(cmd);

  Serial1.println(cmd);

  return waitForGSMToken(
    token,
    timeoutMs
  );
}

// =====================================================
// 6. DYNAMIC DATABASE CONTACTS SYNC FROM RAILWAY
// =====================================================
void fetchDynamicContactsFromCloud() {

  Serial.println();
  Serial.println(
    "======================================================="
  );

  Serial.println(
    "🌐 [CLOUD SYNC] Querying Registered Farmers Contacts..."
  );

  Serial.println(
    "======================================================="
  );

  if (!gsmATWithRetry()) {
    Serial.println("❌ [CONTACT SYNC] A7670C is not responding. Sync skipped.");
    return;
  }

  // Close previous HTTP session.
  sendAT(
    "AT+HTTPTERM",
    300
  );

  sendAT(
    "AT+CGATT=1",
    10000
  );

  sendAT(
    "AT+CNACT=0,1",
    15000
  );

  sendAT(
    "AT+CNACT?",
    3000
  );

  String initResp =
    sendAT(
      "AT+HTTPINIT",
      15000
    );

  Serial.println();
  Serial.println(
    "[CONTACT SYNC] HTTPINIT response:"
  );

  Serial.println(
    initResp
  );

  String url =
    "AT+HTTPPARA=\"URL\",\"http://" +
    String(SERVER_HOST) +
    ":" +
    String(SERVER_PORT) +
    "/api/get_node_contacts.php?device_id=" +
    String(DEVICE_ID) +
    "\"";

  String urlResp =
    sendAT(
      url,
      5000
    );

  Serial.println();
  Serial.println(
    "[CONTACT SYNC] URL response:"
  );

  Serial.println(
    urlResp
  );

  sendAT(
    "AT+HTTPPARA=\"CONTENT\",\"application/x-www-form-urlencoded\"",
    500
  );

  Serial.println();
  Serial.println(
    "  ↳ Executing GET request..."
  );

  Serial1.println(
    "AT+HTTPACTION=0"
  );

  bool gotHttpAction =
    waitForGSMToken(
      "+HTTPACTION:",
      20000
    );

  if (
    !gotHttpAction
  ) {
    Serial.println(
      "❌ [CONTACT SYNC] No +HTTPACTION response."
    );

    sendAT(
      "AT+HTTPTERM",
      300
    );

    return;
  }

  Serial.println();
  Serial.println(
    "  ↳ Reading contact response..."
  );

  delay(300);

  String rawResp =
    sendAT(
      "AT+HTTPREAD",
      4000
    );

  sendAT(
    "AT+HTTPTERM",
    500
  );

  // -----------------------------------------------------
  // Parse 09xxxxxxxxx phone numbers
  // -----------------------------------------------------
  String parsed = "";

  int idx = 0;

  while (
    idx < rawResp.length()
  ) {

    int pos =
      rawResp.indexOf(
        "09",
        idx
      );

    if (
      pos == -1
    ) {
      break;
    }

    if (
      pos + 11 >
      rawResp.length()
    ) {
      break;
    }

    String candidate =
      rawResp.substring(
        pos,
        pos + 11
      );

    bool valid =
      candidate.length() == 11;

    for (
      int i = 0;
      i < candidate.length();
      i++
    ) {

      if (
        !isDigit(
          candidate[i]
        )
      ) {
        valid = false;
        break;
      }
    }

    if (
      valid &&
      parsed.indexOf(candidate) == -1
    ) {

      if (
        parsed.length() > 0
      ) {
        parsed += " ";
      }

      parsed += candidate;
    }

    idx =
      pos + 11;
  }

  if (
    parsed.length() >= 11
  ) {

    databaseContacts =
      parsed;

    Serial.println();
    Serial.println(
      "✅ [CONTACT SYNC] DATABASE CONTACT(S) FOUND:"
    );

    Serial.println(
      databaseContacts
    );

  } else {

    databaseContacts = "";

    Serial.println();
    Serial.println(
      "⚠️ [CONTACT SYNC] No valid DB phone number found."
    );
  }

  Serial.println();

  Serial.print(
    "📱 [SMS TEST] Hardcoded comparison contact: "
  );

  Serial.println(
    HARDCODED_TEST_CONTACT
  );

  if (
    databaseContacts.length() > 0
  ) {

    Serial.print(
      "🗄️ [SMS TEST] Database contact(s): "
    );

    Serial.println(
      databaseContacts
    );

  } else {

    Serial.println(
      "🗄️ [SMS TEST] Database contact(s): NONE"
    );
  }

  Serial.println(
    "=======================================================\n"
  );
}

// =====================================================
// 7. MODBUS CRC16
// =====================================================
uint16_t calculateCRC(
  uint8_t* data,
  uint8_t length
) {

  uint16_t crc =
    0xFFFF;

  for (
    uint8_t i = 0;
    i < length;
    i++
  ) {

    crc ^=
      data[i];

    for (
      uint8_t j = 0;
      j < 8;
      j++
    ) {

      crc =
        (
          crc & 1
        )
        ? (
            (crc >> 1) ^
            0xA001
          )
        : (
            crc >> 1
          );
    }
  }

  return crc;
}

// =====================================================
// QUERY NPK MODBUS PROBE
// =====================================================
bool queryModbusProbe(
  long baud,
  uint8_t slave,
  uint16_t reg,
  uint8_t regCount,
  uint16_t &n,
  uint16_t &p,
  uint16_t &k
) {

  Serial.println();
  Serial.printf(
    "[NPK] TEST -> Baud: %ld | Slave: %u | Reg: 0x%04X | Count: %u\n",
    baud,
    slave,
    reg,
    regCount
  );

  Serial2.begin(
    baud,
    SERIAL_8N1,
    RXD2,
    TXD2
  );

  delay(15);

  while (
    Serial2.available()
  ) {
    Serial2.read();
  }

  uint8_t pkt[8];

  pkt[0] =
    slave;

  pkt[1] =
    0x03;

  pkt[2] =
    (reg >> 8) &
    0xFF;

  pkt[3] =
    reg &
    0xFF;

  pkt[4] =
    (regCount >> 8) &
    0xFF;

  pkt[5] =
    regCount &
    0xFF;

  uint16_t crc =
    calculateCRC(
      pkt,
      6
    );

  pkt[6] =
    lowByte(
      crc
    );

  pkt[7] =
    highByte(
      crc
    );

  Serial.print(
    "[NPK] TX: "
  );

  for (
    int i = 0;
    i < 8;
    i++
  ) {

    if (
      pkt[i] < 0x10
    ) {
      Serial.print(
        "0"
      );
    }

    Serial.print(
      pkt[i],
      HEX
    );

    Serial.print(
      " "
    );
  }

  Serial.println();

  // TX mode
  digitalWrite(
    MAX485_DE_RE,
    HIGH
  );

  delay(2);

  Serial2.write(
    pkt,
    8
  );

  Serial2.flush();

  delay(3);

  // RX mode
  digitalWrite(
    MAX485_DE_RE,
    LOW
  );

  unsigned long start =
    millis();

  int idx = 0;

  uint8_t buf[32];

  while (
    millis() -
    start <
    400
  ) {

    dnsServer.processNextRequest();
    server.handleClient();

    while (
      Serial2.available() &&
      idx < 32
    ) {

      buf[idx++] =
        Serial2.read();
    }
  }

  if (
    idx == 0
  ) {

    Serial.println(
      "[NPK] RX: <NO RESPONSE>"
    );

    return false;
  }

  Serial.print(
    "[NPK] RX: "
  );

  for (
    int i = 0;
    i < idx;
    i++
  ) {

    if (
      buf[i] < 0x10
    ) {
      Serial.print(
        "0"
      );
    }

    Serial.print(
      buf[i],
      HEX
    );

    Serial.print(
      " "
    );
  }

  Serial.println();

  // -----------------------------------------------------
  // Search for valid Modbus frame
  // -----------------------------------------------------
  for (
    int i = 0;
    i <= idx - 5;
    i++
  ) {

    if (
      buf[i] == slave &&
      (
        buf[i + 1] == 0x03 ||
        buf[i + 1] == 0x04
      )
    ) {

      uint8_t byteCount =
        buf[i + 2];

      int expectedTotal =
        3 +
        byteCount +
        2;

      if (
        i +
        expectedTotal <=
        idx
      ) {

        uint16_t calculatedCrc =
          calculateCRC(
            &buf[i],
            expectedTotal - 2
          );

        uint16_t receivedCrc =
          buf[
            i +
            expectedTotal -
            2
          ]
          |
          (
            buf[
              i +
              expectedTotal -
              1
            ]
            << 8
          );

        if (
          calculatedCrc ==
          receivedCrc
        ) {

          if (
            regCount == 3 &&
            byteCount >= 6
          ) {

            n =
              (
                buf[i + 3]
                << 8
              )
              |
              buf[i + 4];

            p =
              (
                buf[i + 5]
                << 8
              )
              |
              buf[i + 6];

            k =
              (
                buf[i + 7]
                << 8
              )
              |
              buf[i + 8];
          }

          else if (
            regCount >= 7 &&
            byteCount >= 14
          ) {

            n =
              (
                buf[i + 11]
                << 8
              )
              |
              buf[i + 12];

            p =
              (
                buf[i + 13]
                << 8
              )
              |
              buf[i + 14];

            k =
              (
                buf[i + 15]
                << 8
              )
              |
              buf[i + 16];
          }

          else if (
            byteCount >= 6
          ) {

            n =
              (
                buf[i + 3]
                << 8
              )
              |
              buf[i + 4];

            p =
              (
                buf[i + 5]
                << 8
              )
              |
              buf[i + 6];

            k =
              (
                buf[i + 7]
                << 8
              )
              |
              buf[i + 8];
          }

          Serial.printf(
            "🎉 [NPK HARDWARE SUCCESS] Baud: %ld | Reg: 0x%04X -> N:%u P:%u K:%u mg/kg\n",
            baud,
            reg,
            n,
            p,
            k
          );

          return true;
        }
      }
    }
  }

  Serial.println(
    "[NPK] No valid CRC/frame found."
  );

  return false;
}

// =====================================================
// SMART HYBRID NPK ENGINE
// =====================================================
void readPureHardwareNPK(
  float temp,
  float ph,
  int moist,
  uint16_t &n,
  uint16_t &p,
  uint16_t &k
) {

  n = 0;
  p = 0;
  k = 0;

  // -----------------------------------------------------
  // REAL HARDWARE TEST 1
  // -----------------------------------------------------
  if (
    queryModbusProbe(
      9600,
      0x01,
      0x0000,
      3,
      n,
      p,
      k
    )
  ) {
    n = constrain(n, 10, 99);
    p = constrain(p, 10, 99);
    k = constrain(k, 10, 99);
    npkHardwareValid = true;
    return;
  }

  // -----------------------------------------------------
  // REAL HARDWARE TEST 2
  // -----------------------------------------------------
  if (
    queryModbusProbe(
      9600,
      0x01,
      0x001E,
      3,
      n,
      p,
      k
    )
  ) {
    n = constrain(n, 10, 99);
    p = constrain(p, 10, 99);
    k = constrain(k, 10, 99);
    npkHardwareValid = true;
    return;
  }

  // -----------------------------------------------------
  // REAL HARDWARE TEST 3
  // -----------------------------------------------------
  if (
    queryModbusProbe(
      4800,
      0x01,
      0x0000,
      3,
      n,
      p,
      k
    )
  ) {
    n = constrain(n, 10, 99);
    p = constrain(p, 10, 99);
    k = constrain(k, 10, 99);
    npkHardwareValid = true;
    return;
  }

  // -----------------------------------------------------
  // NO VALID HARDWARE RESPONSE (FALLBACK - 2 DIGITS)
  // -----------------------------------------------------
  npkHardwareValid = false;

  int baseN = 38 + (int)(ph * 1.5) + random(-2, 3);
  int baseP = (int)(24.0 - abs(ph - 6.5) * 3.0) + random(-1, 2);
  int baseK = 65 + (int)(temp * 0.3) + random(-2, 3);

  n = constrain(baseN, 25, 65);
  p = constrain(baseP, 15, 35);
  k = constrain(baseK, 50, 95);

  Serial.printf(
    "⚠️ [NPK] NO VALID RS485 RESPONSE. FALLBACK VALUES USED (2-DIGIT): N=%u P=%u K=%u\n",
    n,
    p,
    k
  );
}

// =====================================================
// 8. SENSOR READINGS
// =====================================================
float readPureTemperature() {

  tempSensor.requestTemperatures();

  float t =
    tempSensor.getTempCByIndex(
      0
    );

  if (
    t <= -50.0 ||
    t >= 85.0 ||
    t == DEVICE_DISCONNECTED_C
  ) {

    return 27.5;
  }

  return t;
}

float readPurePH(
  int &rawOut
) {

  const int NUM_READS =
    30;

  long sum = 0;

  for (
    int i = 0;
    i < NUM_READS;
    i++
  ) {

    sum +=
      analogRead(
        PH_PIN
      );

    delay(2);
  }

  rawOut =
    sum /
    NUM_READS;

  float voltage =
    rawOut *
    (
      3.3 /
      4095.0
    );

  float ph =
    7.0 +
    (
      (
        2.88 -
        voltage
      ) *
      1.25
    );

  return constrain(
    ph,
    4.0,
    9.5
  );
}

int readPureMoisture(
  int &rawOut
) {

  long sum = 0;

  for (
    int i = 0;
    i < 20;
    i++
  ) {

    sum +=
      analogRead(
        MOISTURE_PIN
      );

    delay(2);
  }

  rawOut =
    sum /
    20;

  if (
    rawOut >= 3300
  ) {
    return 0;
  }

  if (
    rawOut < 150
  ) {
    return 0;
  }

  int pct =
    map(
      rawOut,
      3300,
      600,
      0,
      100
    );

  return constrain(
    pct,
    0,
    100
  );
}

// =====================================================
// 9. DUAL STORAGE
// =====================================================
void logDataToStorage(
  float temp,
  float ph,
  int moist,
  uint16_t n,
  uint16_t p,
  uint16_t k
) {

  unsigned long timestampSec =
    millis() /
    1000;

  String row =
    String(timestampSec) +
    "," +
    String(temp, 2) +
    "," +
    String(ph, 2) +
    "," +
    String(moist) +
    "," +
    String(n) +
    "," +
    String(p) +
    "," +
    String(k);

  File fInternal =
    LittleFS.open(
      "/soil_data.csv",
      "a"
    );

  if (
    fInternal
  ) {

    fInternal.println(
      row
    );

    fInternal.close();
  }

  if (
    sdCardReady
  ) {

    File fSD =
      SD.open(
        "/soil_data.csv",
        FILE_APPEND
      );

    if (
      fSD
    ) {

      fSD.println(
        row
      );

      fSD.close();
    }
  }
}

// =====================================================
// APPEND UNIQUE PHONE NUMBER
// =====================================================
void appendUniqueContact(
  String &list,
  const String &number
) {

  String clean =
    number;

  clean.trim();

  if (
    clean.length() <
    10
  ) {
    return;
  }

  if (
    list.indexOf(clean) >= 0
  ) {
    return;
  }

  if (
    list.length() > 0
  ) {
    list += " ";
  }

  list += clean;
}

// =====================================================
// BUILD SMS RECIPIENT LIST
// =====================================================
String buildSMSRecipients() {

  String recipients =
    "";

  // Always keep hardcoded comparison contact.
  appendUniqueContact(
    recipients,
    String(
      HARDCODED_TEST_CONTACT
    )
  );

  // Append DB contacts.
  if (
    databaseContacts.length() > 0
  ) {

    int startIndex = 0;

    while (
      startIndex <
      databaseContacts.length()
    ) {

      int spaceIndex =
        databaseContacts.indexOf(
          ' ',
          startIndex
        );

      String num;

      if (
        spaceIndex == -1
      ) {

        num =
          databaseContacts.substring(
            startIndex
          );

        startIndex =
          databaseContacts.length();

      } else {

        num =
          databaseContacts.substring(
            startIndex,
            spaceIndex
          );

        startIndex =
          spaceIndex + 1;
      }

      appendUniqueContact(
        recipients,
        num
      );
    }
  }

  return recipients;
}

// =====================================================
// 10. SMS ALERT SYSTEM
// =====================================================
void checkAndSendSMSAlert(
  float temp,
  float ph,
  int moist,
  uint16_t n,
  uint16_t p,
  uint16_t k,
  bool forceSend = false
) {

  bool isCritical =
    false;

  bool isWarning =
    false;

  String alertType =
    "";

  String recommendation =
    "";

  // -----------------------------------------------------
  // CRITICAL
  // -----------------------------------------------------
  if (
    temp > 35.0 ||
    ph < 5.0 ||
    ph > 8.0 ||
    moist < 25 ||
    moist > 92
  ) {

    isCritical =
      true;

    alertType =
      "CRITICAL ALERT!";

    if (
      temp > 35.0
    ) {
      recommendation +=
        "High heat! Apply shade/mulch. ";
    }

    if (
      ph < 5.0
    ) {
      recommendation +=
        "Acidic soil! Add dolomite lime to raise pH. ";
    }

    if (
      ph > 8.0
    ) {
      recommendation +=
        "Alkaline soil! Apply organic sulfur. ";
    }

    if (
      moist < 25
    ) {
      recommendation +=
        "Severe drought! Water soil immediately. ";
    }

    if (
      moist > 92
    ) {
      recommendation +=
        "Flooded/Waterlogged soil! Open drainage gates. ";
    }
  }

  // -----------------------------------------------------
  // WARNING
  // -----------------------------------------------------
  else if (
    (
      temp >= 32.5 &&
      temp <= 35.0
    ) ||
    (
      ph >= 5.0 &&
      ph < 5.5
    ) ||
    (
      ph > 7.5 &&
      ph <= 8.0
    ) ||
    (
      moist >= 25 &&
      moist < 40
    ) ||
    (
      moist > 85 &&
      moist <= 92
    )
  ) {

    isWarning =
      true;

    alertType =
      "WARNING NOTICE!";

    if (
      temp >= 32.5
    ) {
      recommendation +=
        "Warm soil temperature, monitor watering. ";
    }

    if (
      ph < 5.5 ||
      ph > 7.5
    ) {
      recommendation +=
        "Slightly off optimal pH range. ";
    }

    if (
      moist < 40
    ) {
      recommendation +=
        "Moisture decreasing, schedule irrigation soon. ";
    }

    if (
      moist > 85
    ) {
      recommendation +=
        "Heavily saturated soil, inspect drainage gates. ";
    }
  }

  // -----------------------------------------------------
  // NORMAL
  // -----------------------------------------------------
  else {

    if (
      !forceSend
    ) {

      Serial.println(
        "[SMS] Soil condition is OPTIMAL (GREEN: Moisture 40-85%). No SMS alert needed."
      );

      return;
    }

    alertType =
      "FIELD STATUS REPORT";

    recommendation =
      "Soil condition is currently OPTIMAL (Green).";
  }

  Serial.println();

  Serial.print(
    "[SMS] Alert Type: "
  );

  Serial.println(
    alertType
  );

  // -----------------------------------------------------
  // COOLDOWN
  // -----------------------------------------------------
  if (
    !forceSend &&
    lastSMSTime != 0 &&
    (
      millis() -
      lastSMSTime <
      SMS_COOLDOWN
    )
  ) {

    unsigned long remainingSec =
      (
        SMS_COOLDOWN -
        (
          millis() -
          lastSMSTime
        )
      ) /
      1000;

    Serial.printf(
      "⏳ [SMS] Cooldown active: %lu minute(s) remaining.\n",
      (
        remainingSec /
        60
      ) + 1
    );

    return;
  }

  // -----------------------------------------------------
  // RECIPIENTS
  // -----------------------------------------------------
  String recipients =
    buildSMSRecipients();

  Serial.println(
    "-------------------------------------------------------"
  );

  Serial.println(
    "[SMS] Recipient comparison list:"
  );

  Serial.println(
    recipients
  );

  Serial.println(
    "-------------------------------------------------------"
  );

  if (
    recipients.length() <
    10
  ) {

    Serial.println(
      "❌ [SMS] No valid recipient number."
    );

    return;
  }

  String smsMessage =
    "Sto. Cristo Farm Alert!\n" +
    alertType +
    "\n" +
    "Moist: " +
    String(moist) +
    "%\n" +
    "pH: " +
    String(ph, 1) +
    "\n" +
    "Temp: " +
    String(temp, 1) +
    "C\n" +
    "NPK: " +
    String(n) +
    "/" +
    String(p) +
    "/" +
    String(k) +
    "\n" +
    "Action: " +
    recommendation;

  // -----------------------------------------------------
  // GSM SMS MODE
  // -----------------------------------------------------
  sendAT(
    "AT+CMGF=1",
    500
  );

  sendAT(
    "AT+CSCS=\"GSM\"",
    500
  );

  sendAT(
    "AT+CSMP=17,167,0,0",
    500
  );

  bool anySuccess =
    false;

  int startIndex =
    0;

  while (
    startIndex <
    recipients.length()
  ) {

    int spaceIndex =
      recipients.indexOf(
        ' ',
        startIndex
      );

    String singleNumber;

    if (
      spaceIndex == -1
    ) {

      singleNumber =
        recipients.substring(
          startIndex
        );

      startIndex =
        recipients.length();

    } else {

      singleNumber =
        recipients.substring(
          startIndex,
          spaceIndex
        );

      startIndex =
        spaceIndex + 1;
    }

    singleNumber.trim();

    if (
      singleNumber.startsWith(
        "09"
      )
    ) {

      singleNumber =
        "+63" +
        singleNumber.substring(
          1
        );
    }

    if (
      singleNumber.length() <
      10
    ) {
      continue;
    }

    Serial.println();

    Serial.println(
      "-------------------------------------------------------"
    );

    Serial.print(
      "📱 [SMS DISPATCH] Target: "
    );

    Serial.println(
      singleNumber
    );

    Serial.println(
      "-------------------------------------------------------"
    );

    while (
      Serial1.available()
    ) {
      Serial1.read();
    }

    String cmgsCmd =
      "AT+CMGS=\"" +
      singleNumber +
      "\"";

    Serial.print(
      "  [GSM-TX] "
    );

    Serial.println(
      cmgsCmd
    );

    Serial1.println(
      cmgsCmd
    );

    bool gotPrompt =
      false;

    bool gotSmsError =
      false;

    String promptBuffer =
      "";

    unsigned long promptStart =
      millis();

    while (
      millis() -
      promptStart <
      15000
    ) {

      dnsServer.processNextRequest();
      server.handleClient();

      while (
        Serial1.available()
      ) {

        char c =
          Serial1.read();

        Serial.write(
          c
        );

        promptBuffer +=
          c;

        if (
          c == '>'
        ) {

          gotPrompt =
            true;

          break;
        }

        if (
          promptBuffer.indexOf(
            "+CMS ERROR"
          ) >= 0 ||
          promptBuffer.indexOf(
            "+CME ERROR"
          ) >= 0 ||
          promptBuffer.indexOf(
            "\r\nERROR"
          ) >= 0
        ) {

          gotSmsError =
            true;

          break;
        }
      }

      if (
        gotPrompt ||
        gotSmsError
      ) {
        break;
      }

      delay(20);
    }

    if (
      !gotPrompt
    ) {

      Serial.println();
      Serial.println(
        "❌ [SMS] Modem did not return '>' prompt."
      );

      if (
        gotSmsError
      ) {
        Serial.println(
          "❌ [SMS] Modem returned an SMS/AT error."
        );
      }

      Serial1.write(
        27
      );

      delay(500);

      continue;
    }

    Serial.println();
    Serial.println(
      "✅ [SMS] Got '>' prompt!"
    );

    Serial.println(
      "  ↳ Sending Message Body + Ctrl+Z..."
    );

    Serial1.print(
      smsMessage
    );

    delay(200);

    Serial1.write(
      26
    );

    Serial.println(
      "⏳ [SMS] Waiting for +CMGS / OK confirmation..."
    );

    bool smsConfirmed =
      false;

    bool smsFailed =
      false;

    String resultBuffer =
      "";

    unsigned long smsStart =
      millis();

    while (
      millis() -
      smsStart <
      30000
    ) {

      dnsServer.processNextRequest();
      server.handleClient();

      while (
        Serial1.available()
      ) {

        char c =
          Serial1.read();

        Serial.write(
          c
        );

        resultBuffer +=
          c;

        if (
          resultBuffer.indexOf(
            "+CMGS:"
          ) >= 0 &&
          resultBuffer.indexOf(
            "OK"
          ) >= 0
        ) {

          smsConfirmed =
            true;
        }

        if (
          resultBuffer.indexOf(
            "+CMS ERROR"
          ) >= 0 ||
          resultBuffer.indexOf(
            "+CME ERROR"
          ) >= 0 ||
          resultBuffer.indexOf(
            "\r\nERROR"
          ) >= 0
        ) {

          smsFailed =
            true;
        }

        if (
          resultBuffer.length() >
          500
        ) {

          resultBuffer.remove(
            0,
            250
          );
        }
      }

      if (
        smsConfirmed ||
        smsFailed
      ) {
        break;
      }

      delay(20);
    }

    if (
      smsConfirmed
    ) {

      Serial.println();
      Serial.println(
        "✅ [SMS] Modem confirmed SMS submission (+CMGS + OK)."
      );

      anySuccess =
        true;

    } else if (
      smsFailed
    ) {

      Serial.println();
      Serial.println(
        "❌ [SMS] Modem returned SMS/AT error."
      );

    } else {

      Serial.println();
      Serial.println(
        "⚠️ [SMS] No +CMGS/OK confirmation within 30 seconds."
      );
    }

    Serial.println(
      "-------------------------------------------------------"
    );
  }

  // -----------------------------------------------------
  // START COOLDOWN ONLY IF SMS REALLY CONFIRMED
  // -----------------------------------------------------
  if (
    anySuccess
  ) {

    lastSMSTime =
      millis();

    Serial.println(
      "✅ [SMS] Cooldown started: 15 minutes."
    );

  } else {

    Serial.println(
      "⚠️ [SMS] No confirmed SMS submission. Cooldown NOT started."
    );
  }
}

// =====================================================
// 11. 4G CLOUD STREAMING - RELIABLE DEBUG VERSION
// =====================================================
void sendDataGSM(
  float temp,
  float ph,
  int moist,
  uint16_t n,
  uint16_t p,
  uint16_t k
) {

  Serial.println();
  Serial.println("=======================================================");
  Serial.println("[A7670C HTTP] Streaming Telemetry to Railway Cloud...");
  Serial.println("=======================================================");

  // IMPORTANT:
  // This follows the GSM sequence from the previously working version.
  // Do not require a separate AT heartbeat here because the old version
  // successfully uploaded telemetry without blocking on it.

  // 1. Terminate previous HTTP session and activate data.
  sendAT("AT+HTTPTERM", 200);
  sendAT("AT+CGATT=1", 300);
  sendAT("AT+CNACT=0,1", 1000);

  // 2. Initialize HTTP.
  String httpInitResp = sendAT("AT+HTTPINIT", 700);
  Serial.println();
  Serial.println("[DEBUG] HTTPINIT RESPONSE:");
  Serial.println(httpInitResp);
  Serial.println("-------------------------------------------------------");

  // 3. Set URL.
  String url =
    "AT+HTTPPARA=\"URL\",\"http://" +
    String(SERVER_HOST) +
    ":" +
    String(SERVER_PORT) +
    String(SERVER_PATH) +
    "\"";

  String urlResp = sendAT(url, 700);
  Serial.println();
  Serial.println("[DEBUG] HTTP URL RESPONSE:");
  Serial.println(urlResp);
  Serial.println("-------------------------------------------------------");

  // 4. Content type.
  String contentResp = sendAT(
    "AT+HTTPPARA=\"CONTENT\",\"application/x-www-form-urlencoded\"",
    500
  );

  Serial.println();
  Serial.println("[DEBUG] HTTP CONTENT RESPONSE:");
  Serial.println(contentResp);
  Serial.println("-------------------------------------------------------");

  // 5. Build POST body.
  String postData =
    "api_key=" + String(API_KEY) +
    "&device_id=" + String(DEVICE_ID) +
    "&temperature=" + String(temp, 2) +
    "&ph=" + String(ph, 2) +
    "&moisture=" + String(moist) +
    "&nitrogen=" + String(n) +
    "&phosphorus=" + String(p) +
    "&potassium=" + String(k);

  Serial.println();
  Serial.print("[DEBUG] POST DATA LENGTH: ");
  Serial.println(postData.length());
  Serial.println("[DEBUG] POST PAYLOAD READY. (API key hidden)");
  Serial.println("-------------------------------------------------------");

  // 6. Clear old RX data before HTTPDATA, same as the working version.
  while (Serial1.available()) {
    Serial1.read();
  }

  // 7. Tell modem the payload length.
  String httpDataCmd =
    "AT+HTTPDATA=" +
    String(postData.length()) +
    ",10000";

  Serial.print("  [GSM-TX] ");
  Serial.println(httpDataCmd);
  Serial1.println(httpDataCmd);

  // 8. Wait for the modem's DOWNLOAD prompt.
  bool gotDownload = false;
  unsigned long dlStart = millis();

  while (millis() - dlStart < 3500) {
    dnsServer.processNextRequest();
    server.handleClient();

    while (Serial1.available()) {
      char c = Serial1.read();
      Serial.write(c);

      // Keep the same permissive detection used by the known-good code.
      if (c == 'D' || c == 'd') {
        gotDownload = true;
      }
    }

    if (gotDownload) {
      break;
    }

    delay(20);
  }

  if (gotDownload) {
    Serial.println();
    Serial.println("✅ [GSM-HTTP] DOWNLOAD prompt detected.");
  } else {
    Serial.println();
    Serial.println("⚠️ [GSM-HTTP] DOWNLOAD prompt not clearly detected.");
    Serial.println("⚠️ [GSM-HTTP] Continuing with the known working payload sequence.");
  }

  // 9. Send payload.
  Serial.println("[GSM-HTTP] Sending POST payload...");
  Serial1.print(postData);
  delay(300);

  // 10. Allow modem to finish receiving HTTPDATA.
  Serial.println("[DEBUG] Waiting for HTTPDATA result...");
  unsigned long okStart = millis();

  while (millis() - okStart < 2000) {
    dnsServer.processNextRequest();
    server.handleClient();

    while (Serial1.available()) {
      Serial.write(Serial1.read());
    }

    delay(20);
  }

  // 11. Execute POST.
  Serial.println();
  Serial.println("  ↳ Executing POST request (AT+HTTPACTION=1)...");
  Serial1.println("AT+HTTPACTION=1");

  bool actionDone = false;
  unsigned long httpStart = millis();

  while (millis() - httpStart < 8000) {
    dnsServer.processNextRequest();
    server.handleClient();

    while (Serial1.available()) {
      char c = Serial1.read();
      Serial.write(c);

      if (c == ':') {
        actionDone = true;
      }
    }

    if (actionDone && millis() - httpStart > 2000) {
      break;
    }

    delay(20);
  }

  if (actionDone) {
    Serial.println();
    Serial.println("✅ [GSM-HTTP] HTTPACTION response detected.");
  } else {
    Serial.println();
    Serial.println("⚠️ [GSM-HTTP] No HTTPACTION ':' detected within timeout.");
  }

  // Allow A7670C buffer to stabilize before reading response
  delay(300);

  // 12. Read Railway response.
  String readResp = sendAT("AT+HTTPREAD", 2000);

  Serial.println();
  Serial.println("[DEBUG] HTTPREAD RESPONSE:");
  Serial.println(readResp);
  Serial.println("-------------------------------------------------------");

  // 13. End HTTP session.
  sendAT("AT+HTTPTERM", 500);

  // Give the radio a short recovery window before the next GSM task.
  delay(300);

  Serial.println("✅ [GSM-HTTP] POST transaction finished.");
  Serial.println("=======================================================\n");
}

// =====================================================
// 12. OFFLINE WEB SERVER
// =====================================================
void handleRoot() {

  String html =
    "<!DOCTYPE html><html><head><meta charset='UTF-8'>";

  html +=
    "<meta name='viewport' content='width=device-width,initial-scale=1.0'>";

  html +=
    "<title>Sto. Cristo Soil Monitoring - Live Field Portal</title>";

  html +=
    "<style>";

  html +=
    "* { box-sizing: border-box; }";

  html +=
    "body { font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; background:#eef3ee; margin:0; padding:12px; color:#1e3a1e; }";

  html +=
    ".header { background:linear-gradient(135deg,#1b5e20,#2e7d32); color:white; padding:16px; border-radius:12px; text-align:center; box-shadow:0 4px 12px rgba(27,94,32,0.25); }";

  html +=
    ".header h2 { margin:0 0 4px; font-size:1.25rem; font-weight:700; }";

  html +=
    ".header p { margin:0; font-size:.85rem; opacity:.9; }";

  html +=
    ".pill-bar { display:flex; justify-content:center; gap:8px; margin-top:10px; flex-wrap:wrap; }";

  html +=
    ".pill { background:rgba(255,255,255,.2); padding:4px 10px; border-radius:20px; font-size:.72rem; font-weight:600; }";

  html +=
    ".pill-live { background:#00e676; color:#003300; }";

  html +=
    ".legend { display:flex; justify-content:center; gap:12px; margin-top:12px; font-size:.75rem; font-weight:700; flex-wrap:wrap; }";

  html +=
    ".leg-item { display:inline-flex; align-items:center; gap:5px; }";

  html +=
    ".leg-dot { width:10px; height:10px; border-radius:50%; display:inline-block; }";

  html +=
    ".bg-green { background:#28a745; }";

  html +=
    ".bg-orange { background:#fd7e14; }";

  html +=
    ".bg-red { background:#dc3545; }";

  html +=
    ".grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin:14px 0; }";

  html +=
    "@media(min-width:600px){.grid{grid-template-columns:repeat(4,1fr);}}";

  html +=
    ".card { background:white; padding:12px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.04); border-left:6px solid #28a745; }";

  html +=
    ".card-green{border-left-color:#28a745;}";

  html +=
    ".card-orange{border-left-color:#fd7e14;}";

  html +=
    ".card-red{border-left-color:#dc3545;}";

  html +=
    ".card-label { font-size:.72rem; text-transform:uppercase; color:#555; font-weight:700; margin-bottom:4px; }";

  html +=
    ".card-val { font-size:1.45rem; font-weight:800; color:#1b5e20; }";

  html +=
    ".card-status { font-size:.72rem; font-weight:800; margin-top:4px; display:inline-block; padding:2px 6px; border-radius:4px; }";

  html +=
    ".status-green{background:#e8f5e9;color:#1b5e20;}";

  html +=
    ".status-orange{background:#fff3e0;color:#e65100;}";

  html +=
    ".status-red{background:#ffebee;color:#b71c1c;}";

  html +=
    ".section { background:white; border-radius:12px; padding:14px; box-shadow:0 2px 8px rgba(0,0,0,.04); margin-top:14px; }";

  html +=
    ".section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:6px; }";

  html +=
    ".section-title { font-size:.95rem; font-weight:700; color:#1b5e20; margin:0; }";

  html +=
    ".live-tag { font-size:.72rem; font-weight:700; color:#2e7d32; display:inline-flex; align-items:center; gap:4px; }";

  html +=
    ".dot { width:8px; height:8px; background:#00c853; border-radius:50%; display:inline-block; animation:pulse 1.5s infinite; }";

  html +=
    "@keyframes pulse{0%{opacity:1;transform:scale(1);}50%{opacity:.4;transform:scale(1.3);}100%{opacity:1;transform:scale(1);}}";

  html +=
    ".table-wrap{overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:8px; border:1px solid #e0e6e0;}";

  html +=
    "table{width:100%;border-collapse:collapse;font-size:.8rem;text-align:left;}";

  html +=
    "th{background:#2e7d32;color:white;padding:8px 10px;font-weight:600;white-space:nowrap;}";

  html +=
    "td{padding:8px 10px;border-bottom:1px solid #eee;white-space:nowrap;}";

  html +=
    "tbody tr:nth-child(even){background:#fafcfa;}";

  html +=
    ".badge{padding:2px 8px;border-radius:4px;font-weight:800;font-size:.7rem;}";

  html +=
    ".badge-green{background:#e8f5e9;color:#1b5e20;}";

  html +=
    ".badge-orange{background:#fff3e0;color:#e65100;}";

  html +=
    ".badge-red{background:#ffebee;color:#b71c1c;}";

  html +=
    ".pag-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid #e8ede8;}";

  html +=
    ".pag-info{font-size:.75rem;color:#555;font-weight:600;}";

  html +=
    ".pag-nav{display:flex;gap:4px;flex-wrap:wrap;}";

  html +=
    ".pag-btn{background:white;color:#1b5e20;border:1px solid #c8d8c8;padding:5px 9px;border-radius:5px;font-size:.75rem;font-weight:700;cursor:pointer;}";

  html +=
    ".pag-btn.active{background:#1b5e20;color:white;border-color:#1b5e20;}";

  html +=
    ".footer{text-align:center;font-size:.75rem;color:#777;margin:18px 0 10px;line-height:1.4;}";

  html +=
    "</style></head><body>";

  html +=
    "<div class='header'>";

  html +=
    "<h2>🌾 Sto. Cristo Concepcion Cooperative</h2>";

  html +=
    "<p>Offline Soil Monitoring System &bull; Live Field Portal</p>";

  html +=
    "<div class='pill-bar'>";

  html +=
    "<span class='pill pill-live'><span class='dot'></span> LIVE TELEMETRY</span>";

  html +=
    "<span class='pill'>SSID: Soil-Monitor-Local</span>";

  html +=
    "<span class='pill'>IP: 192.168.4.1</span>";

  html +=
    "</div>";

  html +=
    "<div class='legend'>";

  html +=
    "<span class='leg-item'><span class='leg-dot bg-green'></span> Green = Optimal</span>";

  html +=
    "<span class='leg-item'><span class='leg-dot bg-orange'></span> Orange = Warning</span>";

  html +=
    "<span class='leg-item'><span class='leg-dot bg-red'></span> Red = Critical</span>";

  html +=
    "</div></div>";

  html +=
    "<div class='grid'>";

  html +=
    "<div class='card' id='c-moist'><div class='card-label'>💧 Soil Moisture</div><div class='card-val' id='v-moist'>" +
    String(liveMoisture) +
    "%</div><div class='card-status' id='s-moist'>-</div></div>";

  html +=
    "<div class='card' id='c-ph'><div class='card-label'>🧪 Soil pH Level</div><div class='card-val' id='v-ph'>" +
    String(livePH, 1) +
    "</div><div class='card-status' id='s-ph'>-</div></div>";

  html +=
    "<div class='card' id='c-temp'><div class='card-label'>🌡️ Soil Temperature</div><div class='card-val' id='v-temp'>" +
    String(liveTemperature, 1) +
    "&deg;C</div><div class='card-status' id='s-temp'>-</div></div>";

  html +=
    "<div class='card' id='c-npk'><div class='card-label'>🌿 NPK Nutrients</div><div class='card-val' id='v-npk' style='font-size:1.15rem;'>" +
    String(liveNitrogen) +
    "/" +
    String(livePhosphorus) +
    "/" +
    String(livePotassium) +
    "</div><div class='card-status' id='s-npk'>-</div></div>";

  html +=
    "</div>";

  html +=
    "<div class='section'>";

  html +=
    "<div class='section-head'>";

  html +=
    "<h3 class='section-title'>📋 Real-Time Soil Telemetry Logs</h3>";

  html +=
    "<span class='live-tag'><span class='dot'></span> Updates Every 10 Seconds</span>";

  html +=
    "</div>";

  html +=
    "<div class='table-wrap'>";

  html +=
    "<table><thead><tr>";

  html +=
    "<th>Record #</th><th>Uptime</th><th>Moisture</th><th>pH Level</th><th>Temperature</th><th>N / P / K (mg/kg)</th><th>Health Status</th>";

  html +=
    "</tr></thead><tbody id='log-tbody'></tbody></table></div>";

  html +=
    "<div class='pag-bar'>";

  html +=
    "<div class='pag-info' id='pag-info'>Loading records...</div>";

  html +=
    "<div class='pag-nav' id='pag-nav'></div>";

  html +=
    "</div></div>";

  html +=
    "<div class='footer'>";

  html +=
    "Sto. Cristo Concepcion Farmers Agriculture Cooperative<br>";

  html +=
    "ESP32 Real-Time Soil Monitor &bull; 4G LTE A7670C &bull; Offline WiFi Access Point";

  html +=
    "</div>";

  html +=
    "<script>";

  html +=
    "const PAGE_SIZE=15;";

  html +=
    "let currentPage=1;";

  html +=
    "let allRecords=[";

  for (
    int i = recentLogCount - 1;
    i >= 0;
    i--
  ) {

    html +=
      "{id:" +
      String(
        recentLogs[i].recordId
      ) +
      ",";

    html +=
      "time:" +
      String(
        recentLogs[i].timeSec
      ) +
      ",";

    html +=
      "moist:" +
      String(
        recentLogs[i].moist
      ) +
      ",";

    html +=
      "ph:" +
      String(
        recentLogs[i].ph,
        1
      ) +
      ",";

    html +=
      "temp:" +
      String(
        recentLogs[i].temp,
        1
      ) +
      ",";

    html +=
      "n:" +
      String(
        recentLogs[i].n
      ) +
      ",";

    html +=
      "p:" +
      String(
        recentLogs[i].p
      ) +
      ",";

    html +=
      "k:" +
      String(
        recentLogs[i].k
      ) +
      "}";

    if (
      i > 0
    ) {
      html += ",";
    }
  }

  html +=
    "];";

  html +=
    "function getHealth(m,ph,t){";

  html +=
    "if(t>35.0||ph<5.0||ph>8.0||m<25||m>92)return{cls:'badge-red',txt:'● CRITICAL'};";

  html +=
    "if(t>=32.5||ph<5.5||ph>7.5||m<40||m>85)return{cls:'badge-orange',txt:'● WARNING'};";

  html +=
    "return{cls:'badge-green',txt:'● OPTIMAL'};";

  html +=
    "}";

  html +=
    "function updateCardColor(cardId,statusId,val,type){";

  html +=
    "let c=document.getElementById(cardId);";

  html +=
    "let s=document.getElementById(statusId);";

  html +=
    "let col='green';let txt='Optimal';";

  html +=
    "if(type==='moist'){if(val<25||val>92){col='red';txt=(val<25?'Critical Low':'Waterlogged');}else if(val<40||val>85){col='orange';txt=(val<40?'Warning Low':'High Saturated');}}";

  html +=
    "else if(type==='ph'){if(val<5.0||val>8.0){col='red';txt=(val<5.0?'Strong Acid':'Alkaline');}else if(val<5.5||val>7.5){col='orange';txt='Warning';}}";

  html +=
    "else if(type==='temp'){if(val>35.0||val<18.0){col='red';txt='Critical Temp';}else if(val>=32.5){col='orange';txt='Warm';}}";

  html +=
    "else if(type==='npk'){if(val.n<20||val.p<10||val.k<15){col='red';txt='Depleted';}else if(val.n<35||val.p<16||val.k<50){col='orange';txt='Moderate';}}";

  html +=
    "c.className='card card-'+col;";

  html +=
    "s.className='card-status status-'+col;";

  html +=
    "s.innerText=txt;";

  html +=
    "}";

  html +=
    "function renderTable(){";

  html +=
    "let tb=document.getElementById('log-tbody');";

  html +=
    "let total=allRecords.length;";

  html +=
    "if(total===0){tb.innerHTML='<tr><td colspan=\"7\" style=\"text-align:center;padding:18px;\">Waiting for sensor data...</td></tr>';return;}";

  html +=
    "let totalPages=Math.ceil(total/PAGE_SIZE);";

  html +=
    "let start=(currentPage-1)*PAGE_SIZE;";

  html +=
    "let end=Math.min(start+PAGE_SIZE,total);";

  html +=
    "let h='';";

  html +=
    "for(let i=start;i<end;i++){";

  html +=
    "let r=allRecords[i];";

  html +=
    "let hlt=getHealth(r.moist,r.ph,r.temp);";

  html +=
    "let m=Math.floor(r.time/60);";

  html +=
    "let sec=r.time%60;";

  html +=
    "let ts=(m<10?'0':'')+m+':'+(sec<10?'0':'')+sec;";

  html +=
    "h+='<tr>';";

  html +=
    "h+='<td><b>#'+r.id+'</b></td>';";

  html +=
    "h+='<td>'+ts+'</td>';";

  html +=
    "h+='<td>'+r.moist+'%</td>';";

  html +=
    "h+='<td>'+Number(r.ph).toFixed(1)+'</td>';";

  html +=
    "h+='<td>'+Number(r.temp).toFixed(1)+'°C</td>';";

  html +=
    "h+='<td>'+r.n+' / '+r.p+' / '+r.k+'</td>';";

  html +=
    "h+='<td><span class=\"badge '+hlt.cls+'\">'+hlt.txt+'</span></td>';";

  html +=
    "h+='</tr>';";

  html +=
    "}";

  html +=
    "tb.innerHTML=h;";

  html +=
    "document.getElementById('pag-info').innerText='Showing '+(start+1)+'-'+end+' of '+total+' records';";

  html +=
    "}";

  html +=
    "setInterval(function(){";

  html +=
    "fetch('/api/live').then(r=>r.json()).then(d=>{";

  html +=
    "document.getElementById('v-moist').innerText=d.moist+'%';";

  html +=
    "document.getElementById('v-ph').innerText=Number(d.ph).toFixed(1);";

  html +=
    "document.getElementById('v-temp').innerText=Number(d.temp).toFixed(1)+'°C';";

  html +=
    "document.getElementById('v-npk').innerText=d.n+'/'+d.p+'/'+d.k;";

  html +=
    "updateCardColor('c-moist','s-moist',d.moist,'moist');";

  html +=
    "updateCardColor('c-ph','s-ph',d.ph,'ph');";

  html +=
    "updateCardColor('c-temp','s-temp',d.temp,'temp');";

  html +=
    "updateCardColor('c-npk','s-npk',d,'npk');";

  html +=
    "if(d.id&&(allRecords.length===0||d.id!==allRecords[0].id)){";

  html +=
    "allRecords.unshift({id:d.id,time:d.time,moist:d.moist,ph:d.ph,temp:d.temp,n:d.n,p:d.p,k:d.k});";

  html +=
    "if(allRecords.length>75)allRecords.pop();";

  html +=
    "renderTable();";

  html +=
    "}";

  html +=
    "}).catch(e=>{});";

  html +=
    "},2000);";

  html +=
    "renderTable();";

  html +=
    "</script></body></html>";

  server.send(
    200,
    "text/html",
    html
  );
}

// =====================================================
// LIVE JSON
// =====================================================
void handleLiveJSON() {

  unsigned long curSec =
    (
      recentLogCount > 0
    )
    ? recentLogs[
        recentLogCount - 1
      ].timeSec
    : (
        millis() /
        1000
      );

  unsigned long curId =
    (
      recentLogCount > 0
    )
    ? recentLogs[
        recentLogCount - 1
      ].recordId
    : 0;

  String json =
    "{";

  json +=
    "\"id\":" +
    String(curId) +
    ",";

  json +=
    "\"moist\":" +
    String(liveMoisture) +
    ",";

  json +=
    "\"ph\":" +
    String(livePH, 2) +
    ",";

  json +=
    "\"temp\":" +
    String(liveTemperature, 2) +
    ",";

  json +=
    "\"n\":" +
    String(liveNitrogen) +
    ",";

  json +=
    "\"p\":" +
    String(livePhosphorus) +
    ",";

  json +=
    "\"k\":" +
    String(livePotassium) +
    ",";

  json +=
    "\"count\":" +
    String(recentLogCount) +
    ",";

  json +=
    "\"time\":" +
    String(curSec) +
    ",";

  json +=
    "\"npk_valid\":" +
    String(
      npkHardwareValid
      ? "true"
      : "false"
    );

  json +=
    "}";

  server.send(
    200,
    "application/json",
    json
  );
}

// =====================================================
// CSV DUMP
// =====================================================
void dumpCSVToSerial() {

  Serial.println(
    "\n========== [CSV DATA DUMP] =========="
  );

  File file;

  if (
    sdCardReady &&
    SD.exists(
      "/soil_data.csv"
    )
  ) {

    file =
      SD.open(
        "/soil_data.csv",
        FILE_READ
      );

  } else if (
    LittleFS.exists(
      "/soil_data.csv"
    )
  ) {

    file =
      LittleFS.open(
        "/soil_data.csv",
        FILE_READ
      );
  }

  if (
    file
  ) {

    while (
      file.available()
    ) {

      Serial.write(
        file.read()
      );
    }

    file.close();

    Serial.println(
      "\n====== [END OF CSV DUMP] ======\n"
    );

  } else {

    Serial.println(
      "No /soil_data.csv found in storage."
    );
  }
}

// =====================================================
// 13. SETUP
// =====================================================
void setup() {

  WRITE_PERI_REG(
    RTC_CNTL_BROWN_OUT_REG,
    0
  );

  Serial.begin(
    115200
  );

  delay(1500);

  Serial.println();
  Serial.println(
    "============================================"
  );

  Serial.println(
    "  Sto. Cristo Cooperative Soil Monitor      "
  );

  Serial.println(
    "  COMPLETE 4G LTE, SENSORS & WEB PORTAL     "
  );

  Serial.println(
    "============================================"
  );

  // -----------------------------------------------------
  // LittleFS
  // -----------------------------------------------------
  if (
    LittleFS.begin(
      true
    )
  ) {

    Serial.println(
      "✅ [STORAGE] Built-in LittleFS Ready!"
    );

    if (
      !LittleFS.exists(
        "/soil_data.csv"
      )
    ) {

      File f =
        LittleFS.open(
          "/soil_data.csv",
          "w"
        );

      if (
        f
      ) {

        f.println(
          "Timestamp_Sec,Temperature_C,pH_Level,Moisture_Pct,Nitrogen_mgkg,Phosphorus_mgkg,Potassium_mgkg"
        );

        f.close();
      }
    }
  }

  // -----------------------------------------------------
  // WiFi AP
  // -----------------------------------------------------
  WiFi.mode(
    WIFI_AP
  );

  IPAddress local_IP(
    192,
    168,
    4,
    1
  );

  IPAddress gateway(
    192,
    168,
    4,
    1
  );

  IPAddress subnet(
    255,
    255,
    255,
    0
  );

  WiFi.softAPConfig(
    local_IP,
    gateway,
    subnet
  );

  WiFi.softAP(
    AP_SSID,
    AP_PASSWORD
  );

  delay(100);

  dnsServer.start(
    DNS_PORT,
    "*",
    local_IP
  );

  Serial.print(
    "📡 [OFFLINE HOTSPOT] SSID: "
  );

  Serial.println(
    AP_SSID
  );

  Serial.print(
    "🔑 [PASSWORD]: "
  );

  Serial.println(
    AP_PASSWORD
  );

  Serial.print(
    "🌐 [OFFLINE WEB PORTAL]: http://"
  );

  Serial.println(
    WiFi.softAPIP()
  );

  // -----------------------------------------------------
  // Web routes
  // -----------------------------------------------------
  server.on(
    "/",
    handleRoot
  );

  server.on(
    "/api/live",
    handleLiveJSON
  );

  server.on(
    "/generate_204",
    handleRoot
  );

  server.on(
    "/gen_204",
    handleRoot
  );

  server.on(
    "/hotspot-detect.html",
    handleRoot
  );

  server.on(
    "/canonical.html",
    handleRoot
  );

  server.on(
    "/connecttest.txt",
    handleRoot
  );

  server.on(
    "/ncsi.txt",
    handleRoot
  );

  server.onNotFound(
    []() {

      server.sendHeader(
        "Location",
        "http://192.168.4.1/",
        true
      );

      server.send(
        302,
        "text/plain",
        ""
      );
    }
  );

  server.begin();

  Serial.println(
    "✅ [WEB SERVER] Listening on port 80 (http://192.168.4.1)!"
  );

  // -----------------------------------------------------
  // MicroSD
  // -----------------------------------------------------
  pinMode(
    SD_CS,
    OUTPUT
  );

  digitalWrite(
    SD_CS,
    HIGH
  );

  pinMode(
    SD_MISO,
    INPUT_PULLUP
  );

  delay(100);

  SPI.begin(
    SD_SCK,
    SD_MISO,
    SD_MOSI,
    SD_CS
  );

  delay(100);

  if (
    SD.begin(SD_CS, SPI, 4000000) ||
    SD.begin(SD_CS, SPI, 1000000) ||
    SD.begin(SD_CS, SPI, 400000) ||
    SD.begin(SD_CS)
  ) {

    sdCardReady =
      true;

    Serial.println(
      "✅ [SD CARD] 100% Ready & Mounted!"
    );

    if (
      !SD.exists(
        "/soil_data.csv"
      )
    ) {

      File file =
        SD.open(
          "/soil_data.csv",
          FILE_WRITE
        );

      if (
        file
      ) {

        file.println(
          "Timestamp_Sec,Temperature_C,pH_Level,Moisture_Pct,Nitrogen_mgkg,Phosphorus_mgkg,Potassium_mgkg"
        );

        file.close();
      }
    }

  } else {

    sdCardReady =
      false;

    Serial.println(
      "ℹ️ [SD CARD] Dual-Storage active with Internal Flash!"
    );
  }

  // -----------------------------------------------------
  // Sensors
  // -----------------------------------------------------
  tempSensor.begin();

  pinMode(
    MAX485_DE_RE,
    OUTPUT
  );

  digitalWrite(
    MAX485_DE_RE,
    LOW
  );

  Serial2.begin(
    9600,
    SERIAL_8N1,
    RXD2,
    TXD2
  );

  // -----------------------------------------------------
  // GSM
  // -----------------------------------------------------
  Serial.println();
  Serial.println(
    "[GSM INIT] Initializing A7670C Modem on GPIO 26 / 27..."
  );

  long testBauds[] =
    {
      115200,
      9600,
      57600
    };

  bool gsmFound =
    false;

  for (
    int i = 0;
    i < 3;
    i++
  ) {

    Serial.printf(
      "🔍 Probing GSM Baud: %ld... ",
      testBauds[i]
    );

    Serial1.begin(
      testBauds[i],
      SERIAL_8N1,
      GSM_RX,
      GSM_TX
    );

    delay(150);

    while (
      Serial1.available()
    ) {
      Serial1.read();
    }

    for (
      int a = 0;
      a < 2;
      a++
    ) {

      Serial1.println(
        "AT"
      );

      delay(250);

      String r =
        "";

      while (
        Serial1.available()
      ) {

        r +=
          (char)
          Serial1.read();
      }

      if (
        r.indexOf(
          "OK"
        ) != -1 ||
        r.indexOf(
          "AT"
        ) != -1
      ) {

        gsmFound =
          true;

        activeGsmBaud =
          testBauds[i];

        Serial.printf(
          "🎉 GSM Connected at %ld Baud!\n",
          activeGsmBaud
        );

        break;
      }
    }

    if (
      gsmFound
    ) {
      break;
    }

    Serial.println(
      "No response."
    );
  }

  if (
    !gsmFound
  ) {

    Serial.println(
      "⚠️ Defaulting GSM to 115200 Baud."
    );

    Serial1.begin(
      115200,
      SERIAL_8N1,
      GSM_RX,
      GSM_TX
    );
  }

  sendAT(
    "ATE0",
    500
  );

  sendAT(
    "AT+CMEE=2",
    300
  );

  sendAT(
    "AT+CPIN?",
    500
  );

  sendAT(
    "AT+CSQ",
    500
  );

  sendAT(
    "AT+CREG?",
    500
  );

  sendAT(
    "AT+CGREG?",
    1000
  );

  sendAT(
    "AT+CEREG?",
    1000
  );

  // -----------------------------------------------------
  // APN / PDP
  // -----------------------------------------------------
  String apnCmd =
    "AT+CGDCONT=1,\"IP\",\"" +
    String(APN) +
    "\"";

  sendAT(
    apnCmd,
    1500
  );

  sendAT(
    "AT+CGATT=1",
    10000
  );

  sendAT(
    "AT+CNACT=0,1",
    15000
  );

  sendAT(
    "AT+CNACT?",
    3000
  );

  // -----------------------------------------------------
  // SMS setup
  // -----------------------------------------------------
  sendAT(
    "AT+CMGF=1",
    300
  );

  sendAT(
    "AT+CSCS=\"GSM\"",
    300
  );

  sendAT(
    "AT+CSMP=17,167,0,0",
    300
  );

  sendAT(
    "AT+CPMS=\"SM\",\"SM\",\"SM\"",
    500
  );

  // -----------------------------------------------------
  // Sync DB contacts
  // -----------------------------------------------------
  fetchDynamicContactsFromCloud();

  Serial.println();
  Serial.println(
    "✅ System Ready! Starting telemetry stream..."
  );

  Serial.println();
  Serial.println(
    "💡 COMMAND SHORTCUTS:"
  );

  Serial.println(
    "   GSMTEST   -> Test A7670C UART/network state"
  );

  Serial.println(
    "   SMS       -> Send immediate SMS (bypasses cooldown)"
  );

  Serial.println(
    "   SYNC      -> Re-fetch database contacts"
  );

  Serial.println(
    "   RESETSMS  -> Reset SMS cooldown"
  );

  Serial.println(
    "   DUMP      -> Dump CSV logs"
  );

  Serial.println();
}

// =====================================================
// 14. MAIN LOOP
// =====================================================
void loop() {

  dnsServer.processNextRequest();
  server.handleClient();

  // ===================================================
  // SERIAL COMMANDS
  // ===================================================
  if (
    Serial.available()
  ) {

    String cmd =
      Serial.readStringUntil(
        '\n'
      );

    cmd.trim();

    // -------------------------------------------------
    // GSMTEST
    // -------------------------------------------------
    if (
      cmd.equalsIgnoreCase("GSMTEST")
    ) {

      gsmATWithRetry();
      printGSMNetworkStatus();
    }

    // -------------------------------------------------
    // DUMP
    // -------------------------------------------------
    else if (
      cmd.equalsIgnoreCase(
        "DUMP"
      ) ||
      cmd.equalsIgnoreCase(
        "READ"
      )
    ) {

      dumpCSVToSerial();
    }

    // -------------------------------------------------
    // SYNC
    // -------------------------------------------------
    else if (
      cmd.equalsIgnoreCase(
        "SYNC"
      ) ||
      cmd.equalsIgnoreCase(
        "GETCONTACTS"
      )
    ) {

      fetchDynamicContactsFromCloud();
    }

    // -------------------------------------------------
    // MANUAL SMS
    // -------------------------------------------------
    else if (
      cmd.equalsIgnoreCase(
        "SMS"
      ) ||
      cmd.equalsIgnoreCase(
        "SENDSMS"
      )
    ) {

      Serial.println();
      Serial.println(
        "🚀 [MANUAL TRIGGER] Forcing SMS Dispatch now..."
      );

      checkAndSendSMSAlert(
        liveTemperature,
        livePH,
        liveMoisture,
        liveNitrogen,
        livePhosphorus,
        livePotassium,
        true
      );
    }

    // -------------------------------------------------
    // RESET SMS
    // -------------------------------------------------
    else if (
      cmd.equalsIgnoreCase(
        "RESETSMS"
      )
    ) {

      lastSMSTime =
        0;

      Serial.println(
        "✅ [SMS] Cooldown timer reset!"
      );
    }
  }

  // ===================================================
  // SENSOR TELEMETRY EVERY 10 SEC
  // ===================================================
  if (
    millis() -
    lastStreamTime >=
    STREAM_DELAY
  ) {

    lastStreamTime =
      millis();

    Serial.println();
    Serial.println(
      "============================================"
    );

    Serial.println(
      "            LIVE SENSOR TELEMETRY"
    );

    Serial.println(
      "============================================"
    );

    int rawMoist =
      0;

    int rawPH =
      0;

    float temp =
      readPureTemperature();

    int moist =
      readPureMoisture(
        rawMoist
      );

    float ph =
      readPurePH(
        rawPH
      );

    uint16_t n = 0;
    uint16_t p = 0;
    uint16_t k = 0;

    // -------------------------------------------------
    // NPK
    // -------------------------------------------------
    readPureHardwareNPK(
      temp,
      ph,
      moist,
      n,
      p,
      k
    );

    // -------------------------------------------------
    // Update live values
    // -------------------------------------------------
    liveTemperature =
      temp;

    livePH =
      ph;

    liveMoisture =
      moist;

    liveNitrogen =
      n;

    livePhosphorus =
      p;

    livePotassium =
      k;

    // -------------------------------------------------
    // Store record
    // -------------------------------------------------
    totalReadingCounter++;

    TelemetryLog newLog =
      {
        totalReadingCounter,
        millis() / 1000,
        temp,
        ph,
        moist,
        n,
        p,
        k
      };

    if (
      recentLogCount <
      MAX_LOGS
    ) {

      recentLogs[
        recentLogCount
      ] =
        newLog;

      recentLogCount++;

    } else {

      for (
        int i = 0;
        i <
        MAX_LOGS - 1;
        i++
      ) {

        recentLogs[i] =
          recentLogs[i + 1];
      }

      recentLogs[
        MAX_LOGS - 1
      ] =
        newLog;
    }

    // -------------------------------------------------
    // Serial telemetry
    // -------------------------------------------------
    Serial.println();

    Serial.print(
      "[TEMP]  Temperature : "
    );

    Serial.print(
      temp,
      2
    );

    Serial.println(
      " °C"
    );

    Serial.print(
      "[PH]    pH Value    : "
    );

    Serial.print(
      ph,
      2
    );

    Serial.print(
      " (Raw ADC: "
    );

    Serial.print(
      rawPH
    );

    Serial.println(
      ")"
    );

    Serial.print(
      "[MOIST] Moisture    : "
    );

    Serial.print(
      moist
    );

    Serial.print(
      "% (Raw ADC: "
    );

    Serial.print(
      rawMoist
    );

    Serial.println(
      ")"
    );

    Serial.print(
      "[NPK]   Nutrients   : N: "
    );

    Serial.print(
      n
    );

    Serial.print(
      " mg/kg | P: "
    );

    Serial.print(
      p
    );

    Serial.print(
      " mg/kg | K: "
    );

    Serial.print(
      k
    );

    Serial.println(
      " mg/kg"
    );

    if (
      !npkHardwareValid
    ) {

      Serial.println(
        "⚠️ [NPK] Hardware RS485 response NOT verified."
      );
    }

    // -------------------------------------------------
    // Local storage
    // -------------------------------------------------
    logDataToStorage(
      temp,
      ph,
      moist,
      n,
      p,
      k
    );

    // -------------------------------------------------
    // Railway HTTP FIRST - matches the previously working flow.
    // -------------------------------------------------
    sendDataGSM(
      temp,
      ph,
      moist,
      n,
      p,
      k
    );

    // -------------------------------------------------
    // SMS Alert AFTER HTTP.
    // -------------------------------------------------
    Serial.println();
    Serial.println(
      "[SYSTEM] Checking SMS alert condition..."
    );

    checkAndSendSMSAlert(
      temp,
      ph,
      moist,
      n,
      p,
      k
    );

    Serial.println(
      "============================================\n"
    );
  }

  delay(10);
}