#include <Arduino.h>

// ─────────────────────────────────────────────────────────────
// LIVE REAL-TIME HARDWARE CONTINUITY & VOLTAGE MONITOR
// Continuously polls GPIO 26 & 27 every 500ms
// ─────────────────────────────────────────────────────────────

#define PIN_26 26
#define PIN_27 27

void setup() {
  Serial.begin(115200);
  delay(1500);

  Serial.println("\n=======================================================");
  Serial.println("  LIVE REAL-TIME GSM LINE MONITOR                      ");
  Serial.println("=======================================================");
  Serial.println("Makikita mo dito ang live status ng GPIO 26 at 27 bawat segundo.");
  Serial.println("Kapag gising ang GSM Module, magiging [HIGH 3.3V] ang isa sa mga pins!\n");

  pinMode(PIN_26, INPUT);
  pinMode(PIN_27, INPUT);

  Serial1.begin(115200, SERIAL_8N1, 26, 27);
}

unsigned long lastPrint = 0;
int last26 = -1;
int last27 = -1;

void loop() {
  // Check live pin voltages
  if (millis() - lastPrint > 1000) {
    lastPrint = millis();

    int v26 = digitalRead(PIN_26);
    int v27 = digitalRead(PIN_27);

    Serial.printf("⏱️ [LIVE STATUS] GPIO 26: %s | GPIO 27: %s",
                  (v26 == HIGH) ? "🟢 HIGH (3.3V)" : "🔴 LOW (0V)",
                  (v27 == HIGH) ? "🟢 HIGH (3.3V)" : "🔴 LOW (0V)");

    if (v26 == HIGH || v27 == HIGH) {
      Serial.print("  🎉 MAY SIGNAL NA!");
    }
    Serial.println();

    // Ping AT command on Serial1
    Serial1.println("AT");
  }

  // Print any response from GSM module
  while (Serial1.available()) {
    char c = Serial1.read();
    Serial.printf("\n🎉 [GSM RESPONSE]: %c", c);
    while (Serial1.available()) {
      Serial.write(Serial1.read());
    }
    Serial.println();
  }

  if (Serial.available()) {
    String in = Serial.readStringUntil('\n');
    in.trim();
    if (in.length() > 0) {
      Serial.print(">> [SEND] "); Serial.println(in);
      Serial1.println(in);
    }
  }
}
