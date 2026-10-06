
#include <Arduino.h>
#include <HardwareSerial.h>
HardwareSerial GSM(1);
#define GSM_RX 26
#define GSM_TX 27
const long baudRates[] = {
  115200,
  57600,
  38400,
  19200,
  9600,
  4800,
  2400
};
void flushGSM() {
  while (GSM.available()) {
    GSM.read();
  }
}
bool testBaud(long baud) {
  Serial.println();
  Serial.println("========================================");
  Serial.print("TESTING BAUD: ");
  Serial.println(baud);
  Serial.println("========================================");
  GSM.end();
  delay(300);
  GSM.begin(baud, SERIAL_8N1, GSM_RX, GSM_TX);
  delay(1000);
  flushGSM();
  for (int attempt = 1; attempt <= 5; attempt++) {
Serial.print("AT attempt ");
Serial.print(attempt);
Serial.println("/5");

GSM.print("AT\r\n");

unsigned long start = millis();
String response = "";

while (millis() - start < 2500) {

  while (GSM.available()) {

    char c = GSM.read();

    response += c;

    Serial.write(c);
  }

  delay(5);
}

if (response.indexOf("OK") >= 0) {

  Serial.println();
  Serial.println("****************************************");
  Serial.println("       A7670C UART FOUND!");
  Serial.print("       BAUD: ");
  Serial.println(baud);
  Serial.println("****************************************");

  return true;
}

delay(500);

  }
  Serial.println();
  Serial.println("NO OK RESPONSE.");
  return false;
}
void setup() {
  Serial.begin(115200);
  delay(2000);
  Serial.println();
  Serial.println("########################################");
  Serial.println("       A7670C UART BAUD SCANNER");
  Serial.println("########################################");
  Serial.println();
  Serial.println("A7670C TXD -> ESP32 GPIO26");
  Serial.println("A7670C RXD -> ESP32 GPIO27");
  Serial.println("A7670C GND -> ESP32 GND");
  Serial.println();
  bool found = false;
  int totalBauds = sizeof(baudRates) / sizeof(baudRates[0]);
  for (int i = 0; i < totalBauds; i++) {
if (testBaud(baudRates[i])) {

  found = true;

  break;
}

  }
  Serial.println();
  Serial.println("########################################");
  if (found) {
Serial.println("✅ A7670C UART DETECTED");

  } else {
Serial.println("❌ A7670C UART NOT DETECTED");

Serial.println();
Serial.println("Tested:");

for (int i = 0; i < totalBauds; i++) {

  Serial.println(baudRates[i]);
}

  }
  Serial.println("########################################");
}
void loop() {
  // Manual Serial Monitor -> A7670C
  while (Serial.available()) {
char c = Serial.read();

GSM.write(c);

  }
  // A7670C -> Serial Monitor
  while (GSM.available()) {
char c = GSM.read();

Serial.write(c);

  }
  delay(10);
}