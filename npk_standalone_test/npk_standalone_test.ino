#include <Arduino.h>
#include <HardwareSerial.h>

// =====================================================
// NPK RS485 / MAX485 PIN CONFIGURATION
// =====================================================

#define RXD2 16          // MAX485 RO -> Voltage Divider -> GPIO16
#define TXD2 17          // MAX485 DI -> GPIO17
#define MAX485_DE_RE 21  // MAX485 DE + RE -> GPIO21

HardwareSerial modbusSerial(2);

// =====================================================
// MODBUS CRC16
// =====================================================

uint16_t modbusCRC(uint8_t *buffer, uint8_t length) {

  uint16_t crc = 0xFFFF;

  for (uint8_t pos = 0; pos < length; pos++) {

    crc ^= buffer[pos];

    for (uint8_t i = 0; i < 8; i++) {

      if (crc & 0x0001) {
        crc >>= 1;
        crc ^= 0xA001;
      } else {
        crc >>= 1;
      }

    }
  }

  return crc;
}

// =====================================================
// SEND MODBUS REQUEST
// =====================================================

void sendModbusRequest(
  uint8_t slave,
  uint16_t reg,
  uint16_t count
) {

  uint8_t request[8];

  request[0] = slave;
  request[1] = 0x03;
  request[2] = highByte(reg);
  request[3] = lowByte(reg);
  request[4] = highByte(count);
  request[5] = lowByte(count);

  uint16_t crc = modbusCRC(request, 6);

  request[6] = lowByte(crc);
  request[7] = highByte(crc);

  Serial.print("[NPK] TX: ");

  for (int i = 0; i < 8; i++) {

    if (request[i] < 0x10) {
      Serial.print("0");
    }

    Serial.print(request[i], HEX);
    Serial.print(" ");
  }

  Serial.println();

  // ---------------------------------------------------
  // TRANSMIT MODE
  // ---------------------------------------------------

  digitalWrite(MAX485_DE_RE, HIGH);

  delay(5);

  modbusSerial.write(request, 8);
  modbusSerial.flush();

  delay(5);

  // ---------------------------------------------------
  // RECEIVE MODE
  // ---------------------------------------------------

  digitalWrite(MAX485_DE_RE, LOW);
}

// =====================================================
// READ MODBUS RESPONSE
// =====================================================

bool readModbusResponse(
  uint8_t *response,
  uint8_t expectedLength
) {

  uint32_t startTime = millis();

  uint8_t index = 0;

  while ((millis() - startTime) < 1000) {

    while (modbusSerial.available()) {

      uint8_t b = modbusSerial.read();

      if (index < 32) {
        response[index] = b;
        index++;
      }

      if (index >= expectedLength) {
        return true;
      }
    }
  }

  return false;
}

// =====================================================
// TEST NPK
// =====================================================

void testNPK() {

  Serial.println();
  Serial.println("====================================================");
  Serial.println("             NPK RS485 STANDALONE TEST");
  Serial.println("====================================================");

  Serial.println();
  Serial.println("PIN CONFIGURATION:");
  Serial.println("MAX485 RO  -> Voltage Divider -> GPIO16");
  Serial.println("MAX485 DI  -> GPIO17");
  Serial.println("MAX485 DE  -> GPIO21");
  Serial.println("MAX485 RE  -> GPIO21");

  Serial.println();
  Serial.println("MODBUS CONFIGURATION:");
  Serial.println("Baud      : 4800");
  Serial.println("Slave ID  : 1");
  Serial.println("Register  : 0x001E");
  Serial.println("Count     : 3");

  Serial.println();
  Serial.println("[NPK] Sending request...");

  while (modbusSerial.available()) {
    modbusSerial.read();
  }

  sendModbusRequest(
    0x01,
    0x001E,
    3
  );

  uint8_t response[32];

  memset(response, 0, sizeof(response));

  bool received = readModbusResponse(
    response,
    11
  );

  Serial.println();

  if (!received) {

    Serial.println("[NPK] RX: <NO RESPONSE>");

    Serial.println();
    Serial.println("❌ NPK SENSOR DID NOT RESPOND.");

    return;
  }

  Serial.print("[NPK] RX: ");

  for (int i = 0; i < 11; i++) {

    if (response[i] < 0x10) {
      Serial.print("0");
    }

    Serial.print(response[i], HEX);
    Serial.print(" ");
  }

  Serial.println();

  // ===================================================
  // BASIC MODBUS VALIDATION
  // ===================================================

  if (response[0] != 0x01) {

    Serial.println("❌ Invalid Slave ID.");

    return;
  }

  if (response[1] != 0x03) {

    Serial.println("❌ Invalid Modbus Function.");

    return;
  }

  if (response[2] != 0x06) {

    Serial.println("❌ Unexpected byte count.");

    return;
  }

  // ===================================================
  // CRC CHECK
  // ===================================================

  uint16_t receivedCRC =
    response[9] |
    ((uint16_t)response[10] << 8);

  uint16_t calculatedCRC =
    modbusCRC(response, 9);

  Serial.println();

  Serial.print("[NPK] Received CRC : 0x");

  Serial.println(receivedCRC, HEX);

  Serial.print("[NPK] Calculated CRC: 0x");

  Serial.println(calculatedCRC, HEX);

  if (receivedCRC != calculatedCRC) {

    Serial.println("❌ CRC ERROR.");

    return;
  }

  // ===================================================
  // EXTRACT NPK
  // ===================================================

  uint16_t nitrogen =
    ((uint16_t)response[3] << 8) |
    response[4];

  uint16_t phosphorus =
    ((uint16_t)response[5] << 8) |
    response[6];

  uint16_t potassium =
    ((uint16_t)response[7] << 8) |
    response[8];

  Serial.println();
  Serial.println("====================================================");
  Serial.println("              NPK SENSOR RESULT");
  Serial.println("====================================================");

  Serial.print("Nitrogen   : ");
  Serial.print(nitrogen);
  Serial.println(" mg/kg");

  Serial.print("Phosphorus : ");
  Serial.print(phosphorus);
  Serial.println(" mg/kg");

  Serial.print("Potassium  : ");
  Serial.print(potassium);
  Serial.println(" mg/kg");

  Serial.println();
  Serial.println("✅ NPK HARDWARE RESPONSE VERIFIED.");
  Serial.println("====================================================");
}

// =====================================================
// SETUP
// =====================================================

void setup() {

  Serial.begin(115200);

  delay(2000);

  Serial.println();
  Serial.println("====================================================");
  Serial.println("       ESP32 NPK / RS485 STANDALONE TEST");
  Serial.println("====================================================");

  // MAX485 direction control
  pinMode(MAX485_DE_RE, OUTPUT);

  // Start in RECEIVE mode
  digitalWrite(MAX485_DE_RE, LOW);

  // ESP32 UART2
  modbusSerial.begin(
    4800,
    SERIAL_8N1,
    RXD2,
    TXD2
  );

  delay(500);

  testNPK();
}

// =====================================================
// LOOP
// =====================================================

void loop() {

  // Test every 5 seconds
  delay(5000);

  testNPK();
}