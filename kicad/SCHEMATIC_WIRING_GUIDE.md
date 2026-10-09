# 📐 Sto. Cristo Soil Monitoring Node - KiCad Schematic & Hardware Documentation

This document contains the complete **Electrical Schematic Netlist**, **Bill of Materials (BOM)**, and **Pin Connection Matrix** for the ESP32 Agricultural Soil Monitoring System.

---

## 1. 📋 Bill of Materials (BOM)

| Item # | Component / Module | Quantity | Specifications / Operating Voltage | Connected Pins / Interfaces |
|---|---|---|---|---|
| **1** | **ESP32 DevKit V1** (30-pin) | 1 | 3.3V Logic, Dual Core 240MHz, WiFi & BLE | Central Microcontroller |
| **2** | **LM2596 DC-DC Buck Converter** | 1 | IN: 7V-35V (12V Battery), OUT: 5.4V DC, 3A Max | System Power Management |
| **3** | **SIMCom A7670C 4G LTE Module** | 1 | 5V VIN, 115200 Baud, 4G LTE / 2G GSM | UART1 (TXD: GPIO 26, RXD: GPIO 27, PEN: 5V, PWK: GND) |
| **4** | **MAX485 TTL-to-RS485 Module** | 1 | 5V VCC, Half-Duplex RS485 Transceiver | UART2 (RO: GPIO 16, DI: GPIO 17, DE/RE: GPIO 21) |
| **5** | **RS485 NPK / 7-in-1 Soil Sensor** | 1 | 9V-24V DC (12V Powered), Modbus RTU | RS485 Differential Bus (A: Yellow, B: Blue, 12V: Brown, GND: Black) |
| **6** | **pH-4502C Analog pH Sensor** | 1 | 5V VCC, 0-14 pH range, Analog Out | ADC1 (Po: GPIO 35, VCC: 5V, GND: GND) |
| **7** | **Capacitive Soil Moisture Sensor v1.2** | 1 | 3.3V VCC, Corrosion-resistant Analog Out | ADC1 (AOUT: GPIO 34, VCC: 3.3V, GND: GND) |
| **8** | **DS18B20 Temperature Probe** | 1 | 3.0V-5.5V (3.3V Powered), Stainless Steel Waterproof | OneWire (DATA: GPIO 22 + 4.7kΩ Pullup to 3.3V) |
| **9** | **MicroSD Card Adapter Module** | 1 | 3.3V / 5V VCC, SPI Interface | VSPI (CS: GPIO 5, SCK: GPIO 18, MISO: GPIO 19, MOSI: GPIO 23) |
| **10** | **4.7kΩ Resistor** | 1 | 1/4W Through-Hole Resistor | OneWire Pull-up (Between GPIO 22 & 3.3V) |
| **11** | **12V Lead-Acid / Li-Ion Battery** | 1 | 12V DC, 7Ah / 12V 2A Adapter | Primary Node Power Source |
| **12** | **Terminal Blocks & Breadboard / PCB** | 1 | 2-pin / 3-pin Screw Terminals (5.08mm pitch) | Power and Sensor Distribution Rails |

---

## 2. 🔌 Complete Pin Connection & Netlist Matrix

### A. Power Distribution System
* **12V Positive Rail (`+12V`):**
  * `12V Battery (+)`
  * `LM2596 VIN (+)`
  * `NPK Sensor Brown Wire (+)`
* **12V Negative Rail (`12V_GND`):**
  * `12V Battery (-)`
  * `LM2596 VIN (-)`
  * `NPK Sensor Black Wire (-)`
* **5V Regulated Common Bus (`+5V` from LM2596 VOUT+):**
  * `ESP32 VIN / 5V Pin`
  * `GSM A7670C VIN`
  * `GSM A7670C PEN Pin` *(Auto-Power Enable)*
  * `MAX485 VCC Pin`
  * `pH Sensor Module VCC Pin`
  * `MicroSD Module VCC Pin`
* **Common Ground Plane (`GND` from LM2596 VOUT-):**
  * `ESP32 GND Pins`
  * `GSM A7670C GND`
  * `GSM A7670C PWK Pin` *(Power Key Tied to GND)*
  * `MAX485 GND Pin`
  * `pH Sensor Module GND Pin`
  * `Soil Moisture Sensor GND Pin`
  * `DS18B20 Temp Sensor Black Wire (GND)`
  * `MicroSD Module GND Pin`

---

### B. ESP32 GPIO Pinout Mapping

| ESP32 GPIO | Pin Function | Target Device / Module | Wire Color / Target Pin |
|---|---|---|---|
| **GPIO 5** | VSPI CS (Chip Select) | MicroSD Card Module | CS Pin |
| **GPIO 16** | UART2 RX (RX2) | MAX485 Module | RO (Receiver Output) |
| **GPIO 17** | UART2 TX (TX2) | MAX485 Module | DI (Driver Input) |
| **GPIO 18** | VSPI SCK (Clock) | MicroSD Card Module | SCK / CLK Pin |
| **GPIO 19** | VSPI MISO (Master In Slave Out) | MicroSD Card Module | MISO / DO Pin |
| **GPIO 21** | Digital Output | MAX485 Module | DE & RE (Jumpered together) |
| **GPIO 22** | OneWire Digital Bus | DS18B20 Temp Sensor | Data Wire (Yellow/Blue) + 4.7kΩ to 3.3V |
| **GPIO 23** | VSPI MOSI (Master Out Slave In) | MicroSD Card Module | MOSI / DI Pin |
| **GPIO 26** | UART1 RX (RX1) | GSM A7670C 4G LTE | Module TXD Pin |
| **GPIO 27** | UART1 TX (TX1) | GSM A7670C 4G LTE | Module RXD Pin |
| **GPIO 34** | ADC1 Channel 6 | Capacitive Moisture Sensor | Analog Out (AOUT) Pin |
| **GPIO 35** | ADC1 Channel 7 | Analog pH Module (pH-4502C) | Analog pH Out (Po) Pin |
| **3V3 (3.3V)** | Regulated 3.3V Out | Moisture & Temp Sensor | Moisture VCC, DS18B20 Red Wire, 4.7kΩ Pullup |

---

## 3. 🗺️ Electrical System Diagram

```mermaid
graph TD
    subgraph POWER_SOURCE ["🔋 Power Supply Section"]
        BAT["12V DC Battery / Adapter"]
        LM["LM2596 Buck Converter<br/>(12V in -> 5.4V out)"]
        BAT -->|12V (+)| LM
        BAT -->|GND (-)| LM
    end

    subgraph MCU ["🧠 Microcontroller"]
        ESP["ESP32 DevKit V1<br/>(30-Pin NodeMCU)"]
    end

    subgraph TELEMETRY ["📡 Communication Modules"]
        GSM["SIMCom A7670C<br/>4G LTE Modem"]
        MAX["MAX485 Module<br/>(RS485 Transceiver)"]
        NPK_PROBE["RS485 NPK / 7-in-1<br/>Soil Sensor Probe"]
    end

    subgraph SENSORS ["🌱 Soil Sensors & Storage"]
        PH["Analog pH-4502C<br/>Probe Module"]
        MOIST["Capacitive Soil<br/>Moisture Sensor"]
        TEMP["DS18B20 Waterproof<br/>Digital Temp Probe"]
        SD["MicroSD Card<br/>Adapter Module (VSPI)"]
    end

    %% Power Wiring
    BAT ==>|12V (+) Brown Wire| NPK_PROBE
    BAT ==>|GND (-) Black Wire| NPK_PROBE

    LM -->|5.4V VCC| ESP
    LM -->|5.4V VIN & PEN| GSM
    LM -->|5.0V VCC| MAX
    LM -->|5.0V VCC| PH
    LM -->|5.0V VCC| SD
    LM -.->|Common GND| ESP
    LM -.->|Common GND| GSM
    LM -.->|Common GND| MAX
    LM -.->|Common GND| PH
    LM -.->|Common GND| MOIST
    LM -.->|Common GND| TEMP
    LM -.->|Common GND| SD

    ESP -->|3.3V Out| MOIST
    ESP -->|3.3V Out| TEMP

    %% Data Lines
    GSM -->|TXD -> GPIO 26| ESP
    ESP -->|GPIO 27 -> RXD| GSM

    MAX -->|RO -> GPIO 16| ESP
    ESP -->|GPIO 17 -> DI| MAX
    ESP -->|GPIO 21 -> DE/RE| MAX
    MAX <==>|A Terminal (Yellow)| NPK_PROBE
    MAX <==>|B Terminal (Blue)| NPK_PROBE

    PH -->|Po -> GPIO 35| ESP
    MOIST -->|AOUT -> GPIO 34| ESP
    TEMP -->|OneWire -> GPIO 22| ESP

    ESP -->|GPIO 5 (CS)| SD
    ESP -->|GPIO 18 (SCK)| SD
    ESP -->|GPIO 19 (MISO)| SD
    ESP -->|GPIO 23 (MOSI)| SD
```

---

## 4. 📂 Paano Buksan sa KiCad:

1. I-install ang **KiCad 7** o **KiCad 8** (libre mula sa [kicad.org](https://kicad.org)).
2. Pumunta sa folder na:
   `c:\Users\TRISHIA SORIANO\OneDrive\Desktop\duplicate_soil_monitoring\kicad\`
3. I-double click ang **`soil_monitoring.kicad_pro`**.
4. Magbubukas ang KiCad Project Manager. I-click ang **Schematic Editor** (`soil_monitoring.kicad_sch`) para makita at mai-export ang schematic bilang **PDF**, **PNG**, o **SVG** para sa inyong thesis manuscript!
