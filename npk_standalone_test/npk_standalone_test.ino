#include <Arduino.h>
#include <HardwareSerial.h>

#define RXD2 16
#define TXD2 17
#define MAX485_DE_RE 21

HardwareSerial modbusSerial(2);

void setup() {

  Serial.begin(115200);
  delay(2000);

  pinMode(MAX485_DE_RE, OUTPUT);

  // RECEIVE MODE
  digitalWrite(MAX485_DE_RE, LOW);

  modbusSerial.begin(
    4800,
    SERIAL_8N1,
    RXD2,
    TXD2
  );

  Serial.println();
  Serial.println("========================================");
  Serial.println("       MAX485 RX DIAGNOSTIC TEST");
  Serial.println("========================================");
  Serial.println();
  Serial.println("RO  -> GPIO16");
  Serial.println("DI  -> GPIO17");
  Serial.println("DE/RE -> GPIO21");
  Serial.println("Baud -> 4800");
  Serial.println();
  Serial.println("MAX485 is now in RECEIVE mode.");
  Serial.println("Waiting for incoming data...");
}

void loop() {

  if (modbusSerial.available()) {

    Serial.println();
    Serial.println("[RX] DATA DETECTED!");

    while (modbusSerial.available()) {

      byte b = modbusSerial.read();

      Serial.print("0x");

      if (b < 0x10) {
        Serial.print("0");
      }

      Serial.print(b, HEX);

      Serial.print("  DEC:");

      Serial.println(b);
    }
  }

  delay(10);
}