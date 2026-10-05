#include <Arduino.h>

#define RO_PIN 16
#define DE_RE_PIN 21

void setup()
{
  Serial.begin(115200);

  delay(2000);

  pinMode(RO_PIN, INPUT);
  pinMode(DE_RE_PIN, OUTPUT);

  // Receive mode
  digitalWrite(DE_RE_PIN, LOW);

  Serial.println();
  Serial.println("====================================");
  Serial.println("MAX485 RO DIRECT TEST");
  Serial.println("====================================");
}

void loop()
{
  Serial.print("GPIO16 = ");
  Serial.println(digitalRead(RO_PIN));

  delay(100);
}