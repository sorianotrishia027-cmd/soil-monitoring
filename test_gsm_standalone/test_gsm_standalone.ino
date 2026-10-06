#include <Arduino.h>
#include <HardwareSerial.h>
HardwareSerial GSM(1);
#define GSM_RX 26
#define GSM_TX 27
const long GSM_BAUD = 115200;
const char* SERVER_HOST = "soil-monitoring-production.up.railway.app";
const int SERVER_PORT = 80;
const char* SERVER_PATH = "/api/store_data.php";
const char* API_KEY = "SCC_AGRI_SECRET_KEY_2026";
const char* DEVICE_ID = "ESP32_GSM_01";
String sendAT(String command, unsigned long timeout = 5000) {
  while (GSM.available()) {
    GSM.read();
  }
  Serial.println();
  Serial.print("[GSM-TX] ");
  Serial.println(command);
  GSM.print(command);
  GSM.print("\r\n");
  String response = "";
  unsigned long start = millis();
  while (millis() - start < timeout) {
while (GSM.available()) {

  char c = GSM.read();

  response += c;
  Serial.write(c);
}

delay(5);

  }
  return response;
}
bool waitForDownload(unsigned long timeout) {
  String response = "";
  unsigned long start = millis();
  while (millis() - start < timeout) {
while (GSM.available()) {

  char c = GSM.read();

  response += c;
  Serial.write(c);

  if (response.indexOf("DOWNLOAD") >= 0) {
    return true;
  }
}

delay(5);

  }
  return false;
}
void setup() {
  Serial.begin(115200);
  delay(2000);
  GSM.begin(
    GSM_BAUD,
    SERIAL_8N1,
    GSM_RX,
    GSM_TX
  );
  delay(2000);
  Serial.println();
  Serial.println("==================================================");
  Serial.println("        A7670C -> RAILWAY GSM TEST");
  Serial.println("==================================================");
  Serial.println();
  Serial.println("A7670C TXD -> ESP32 GPIO26");
  Serial.println("A7670C RXD -> ESP32 GPIO27");
  Serial.println("UART -> 115200");
  // =================================================
  // GSM CHECK
  // =================================================
  sendAT("AT", 3000);
  sendAT("ATE0", 3000);
  sendAT("AT+CPIN?", 5000);
  sendAT("AT+CSQ", 5000);
  sendAT("AT+CREG?", 5000);
  sendAT("AT+CGREG?", 5000);
  sendAT("AT+CEREG?", 5000);
  sendAT("AT+COPS?", 10000);
  sendAT("AT+CGATT?", 5000);
  sendAT("AT+CGPADDR=1", 5000);
  // =================================================
  // HTTP INIT
  // =================================================
  Serial.println();
  Serial.println("==================================================");
  Serial.println("                HTTP INIT");
  Serial.println("==================================================");
  sendAT("AT+HTTPTERM", 3000);
  delay(1000);
  String httpInit = sendAT("AT+HTTPINIT", 5000);
  if (httpInit.indexOf("OK") < 0) {
Serial.println();
Serial.println("ERROR: HTTPINIT FAILED");

return;

  }
  // =================================================
  // URL
  // =================================================
  String url =
    String("http://") +
    SERVER_HOST +
    ":" +
    String(SERVER_PORT) +
    SERVER_PATH;
  Serial.println();
  Serial.println("==================================================");
  Serial.println("                  HTTP URL");
  Serial.println("==================================================");
  Serial.println(url);
  // Build URL command WITHOUT escaped quotes
  String urlCommand = "AT+HTTPPARA=";
  urlCommand += char(34);
  urlCommand += "URL";
  urlCommand += char(34);
  urlCommand += ",";
  urlCommand += char(34);
  urlCommand += url;
  urlCommand += char(34);
  sendAT(urlCommand, 5000);
  // =================================================
  // PAYLOAD
  // =================================================
  String payload;
  payload =
    "api_key=" + String(API_KEY) +
    "&device_id=" + String(DEVICE_ID) +
    "&temperature=27.50" +
    "&ph=7.00" +
    "&moisture=55" +
    "&nitrogen=50" +
    "&phosphorus=24" +
    "&potassium=76";
  Serial.println();
  Serial.println("==================================================");
  Serial.println("                 TEST PAYLOAD");
  Serial.println("==================================================");
  Serial.println(payload);
  Serial.print("Payload length: ");
  Serial.println(payload.length());
  // =================================================
  // HTTP DATA
  // =================================================
  String dataCommand =
    "AT+HTTPDATA=" +
    String(payload.length()) +
    ",15000";
  Serial.println();
  Serial.print("[GSM-TX] ");
  Serial.println(dataCommand);
  GSM.print(dataCommand);
  GSM.print("\r\n");
  bool downloadReady = waitForDownload(10000);
  if (!downloadReady) {
Serial.println();
Serial.println("ERROR: NO DOWNLOAD PROMPT");

sendAT("AT+HTTPTERM", 3000);

return;

  }
  Serial.println();
  Serial.println("DOWNLOAD PROMPT RECEIVED");
  delay(500);
  // =================================================
  // SEND PAYLOAD
  // =================================================
  Serial.println();
  Serial.println("Sending payload...");
  GSM.print(payload);
  delay(3000);
  while (GSM.available()) {
char c = GSM.read();

Serial.write(c);

  }
  // =================================================
  // HTTP POST
  // =================================================
  Serial.println();
  Serial.println("==================================================");
  Serial.println("                 HTTP POST");
  Serial.println("==================================================");
  sendAT("AT+HTTPACTION=1", 30000);
  delay(3000);
  // =================================================
  // HTTP READ
  // =================================================
  Serial.println();
  Serial.println("==================================================");
  Serial.println("                HTTP READ");
  Serial.println("==================================================");
  sendAT("AT+HTTPREAD", 15000);
  // =================================================
  // HTTP TERM
  // =================================================
  sendAT("AT+HTTPTERM", 5000);
  Serial.println();
  Serial.println("==================================================");
  Serial.println("                 TEST FINISHED");
  Serial.println("==================================================");
  Serial.println();
}
void loop() {
  while (Serial.available()) {
char c = Serial.read();

GSM.write(c);

  }
  while (GSM.available()) {
char c = GSM.read();

Serial.write(c);

  }
  delay(10);
}