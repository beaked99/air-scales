# AIR SCALES - ESP32-S3 Design Review & Requirements

**Project:** Air Scales  
**Revision:** A  
**Date:** 2025-11-15  
**Board:** 4-layer PCB, 1.6mm thickness  

---

## CURRENT DESIGN SUMMARY

### Component Count
- **ICs/Modules:** 9
- **Resistors:** 13
- **Capacitors:** 20
- **Diodes:** 4
- **Switches:** 2
- **Connectors:** 1
- **Inductors:** 2
- **Total:** 51 components

### Key ICs Identified
1. **U9** - ESP32-S3-WROOM-1U-N16R8 (Main MCU)
2. **U1** - BME280 (Environmental Sensor - Temperature, Humidity, Pressure)
3. **U3** - SMA6T28AY (TVS Diode for ESD Protection)
4. **U5** - LM74610QDGKTQ1 (Ideal Diode Controller)
5. **U6** - LM63625DQPWPRQ1 (Buck Converter)
6. **U7** - TPS2553DRVR (Current-Limited Power Switch)
7. **U8** - TPS2121RUXR (Power Multiplexer)

---

## DESIGN REQUIREMENTS QUESTIONNAIRE

### 1. POWER SYSTEM
**Please provide:**
- [ ] Input voltage range (USB? Battery? Both?) USB-C at 5V yes. 12V DC power supply from vehicle yes. Both - yes, but likely never at the same time. Backup Battery or solar - not at this time. 
  - USB-C 5V? ___________
  - Battery voltage: ___________
  - Solar/external input? ___________

- [ ] Target battery type and capacity. No. 
  - Li-ion/Li-Po? ___________
  - Voltage: 3.7V / 4.2V max typical?
  - Capacity (mAh): ___________
  - Single cell or multi-cell? ___________

- [ ] Charging requirements. No charging requirements at this time
  - Charging current: ___________
  - Battery protection needed? Y/N
  - Charge indicator LED? Y/N

- [ ] Power consumption targets. I dont really care about power consumption at this stage as we are not running off batteries. 
  - Active mode current: ___________
  - Sleep mode current: ___________
  - Deep sleep current: ___________
  - Target battery life: ___________

### 2. ESP32 CONFIGURATION
- [ ] Boot mode selection
  - Automatic boot (with EN/BOOT buttons)? ___________ Yes. this is what the buttons are for. I can add more buttons if required. 
  - Strapping pins configured correctly? ___________ 

- [ ] Programming interface
  - USB-to-UART on board? Y/N - what is this going to be for? 
  - External programmer header? Y/N. Yes, there will be external programming headers included. I think this is pin 36 and 37 on the ESP32. the main programming will be done via usb-c
  - Auto-reset circuit? Y/N yes

- [ ] GPIO usage plan (CRITICAL - fill this out!)
  ```
  GPIO0:  _______________ (Note: Boot button typically) yes. you can see this from the schematic
  GPIO1:  _______________
  GPIO2:  _______________
  GPIO3:  _______________
  GPIO4:  _______________
  GPIO5:  _______________
  GPIO6:  _______________
  GPIO7:  _______________
  GPIO8:  _______________ SCL
  GPIO9:  _______________ SDA
  GPIO10: _______________
  GPIO11: _______________
  GPIO12: _______________
  GPIO13: _______________
  GPIO14: _______________
  GPIO15: _______________
  GPIO16: _______________
  GPIO17: _______________
  GPIO18: _______________
  GPIO19: _______________ d-
  GPIO20: _______________ d+
  GPIO21: _______________
  GPIO43/44: ___________ (UART for programming)
  ```

### 3. SENSORS & PERIPHERALS
- [ ] BME280 sensor interface
  - I2C or SPI? ___________
  - I2C address: 0x76 or 0x77? ___________
  - SCL/SDA pins: ___________

- [ ] Load cell / scale interface
  - HX711 ADC? ___________
  - How many load cells? ___________
  - DOUT/SCK pins: ___________

- [ ] Additional sensors needed?
  - Accelerometer/Gyro? ___________
  - Other: ___________

### 4. WIRELESS CONNECTIVITY
- [ ] WiFi antenna
  - PCB trace antenna? ___________
  - U.FL connector for external? ___________
  - Ceramic chip antenna? ___________

- [ ] BLE usage
  - BLE required? Y/N
  - Simultaneous WiFi+BLE? Y/N

### 5. USER INTERFACE
- [ ] Buttons
  - BOOT button: Y/N yes
  - RESET button: Y/N yes
  - User buttons: How many? ___________ not sure if we need any additional ones tbh. maybe we will, but not at the moment i dont think. 
  - Button GPIOs: ___________

- [ ] LEDs
  - Power LED: Y/N yes
  - Status LED: How many? ___________ yes, i will want a few i think. this way i can set up troubleshooting and various other things. like do i have comms coming from the main air sensor, etc. maybe we can walk thru what ones we will want at a later stage??? 
  - LED GPIOs: ___________

- [ ] Display
  - Type: None / OLED / LCD / E-Paper? ___________ none
  - Interface: I2C / SPI? ___________
  - Resolution: ___________

### 6. COMMUNICATION INTERFACES
- [ ] USB
  - USB-C connector: Y/N yes. 
  - USB data lines to ESP32? Y/N (native USB) yes
  - UART-to-USB chip instead? ___________ what is this? 

- [ ] External communication
  - UART pins exposed: Y/N  I dont know
  - I2C pins exposed: Y/N i dont know
  - SPI pins exposed: Y/N i dont know

### 7. MECHANICAL / PHYSICAL irrelevant right now
- [ ] Board dimensions irrelvant right now
  - Length: ___________ mm
  - Width: ___________ mm
  - Max height: ___________ mm

- [ ] Mounting
  - Mounting holes: How many? ___________
  - Hole size: M2 / M3 / M4? ___________
  - Standoff height: ___________ mm

- [ ] Enclosure requirements
  - Weatherproof? Y/N
  - Access holes for buttons? ___________
  - LED light pipes? ___________

### 8. PRODUCTION / ASSEMBLY This will be done wherever it is cheaper. I know I have too many custom components to use the cheaper option so it will be full custom. 
- [ ] Manufacturing
  - Assembly house: JLCPCB / PCBWay / Other? ___________
  - Component availability from assembly house? ___________
  - Quantity for first run: ___________1

- [ ] Testing
  - Test points needed? Y/N yes, i will want to ensure I have various places to test connectivity to determine if anything has failed. 
  - Programming/test jig required? Y/N not at this point in time. 

---

## CRITICAL SCHEMATIC CHECKS TO PERFORM

### ESP32-S3 BOOT CIRCUIT
- [ ] EN (Enable) pin pulled high with 10kΩ, 0.1µF cap to GND
- [ ] GPIO0 pulled high with 10kΩ, connected to BOOT button to GND
- [ ] RESET button connected to EN pin
- [ ] All strapping pins checked (GPIO0, GPIO45, GPIO46)

### POWER SUPPLY
- [ ] 3.3V LDO or Buck converter rated for ESP32 current (500mA+ peak)
- [ ] Input decoupling capacitors (10µF + 0.1µF minimum)
- [ ] Output decoupling capacitors near ESP32 (10µF + 0.1µF minimum)
- [ ] Power good indicator connected to EN if using supervisor

### DECOUPLING CAPACITORS
- [ ] ESP32 VDD pins: 0.1µF ceramic cap on EACH VDD pin
- [ ] 10µF bulk cap near ESP32 power pins
- [ ] BME280: 0.1µF on VDD
- [ ] Each IC: 0.1µF on VDD pin

### CRYSTAL/OSCILLATOR (if external)
- [ ] Load capacitors calculated correctly (typically 10-22pF)
- [ ] Crystal traces short and symmetric
- [ ] Ground plane under crystal for stability

### USB / PROGRAMMING
- [ ] D+/D- lines: 22Ω series resistors if needed
- [ ] ESD protection on USB lines
- [ ] VBUS detection if using USB power

### ANTENNA
- [ ] Keep-out zone around antenna (5mm minimum)
- [ ] No ground plane under antenna
- [ ] 50Ω controlled impedance if using trace antenna
- [ ] Matching network (LC filter) if required

### PROTECTION
- [ ] ESD protection on all external connectors
- [ ] Reverse polarity protection on battery/power input
- [ ] Overcurrent protection if needed
- [ ] TVS diodes on sensitive inputs

### GENERAL
- [ ] All IC pins connected or explicitly marked NC
- [ ] All power pins connected to correct voltage
- [ ] All ground pins connected
- [ ] Pull-up/pull-down resistors on I2C lines (typically 4.7kΩ)
- [ ] Series resistors on LED current limiting
- [ ] Test points on critical signals
- [ ] Silkscreen labels clear and correct

---

## BILL OF MATERIALS (BOM) - TO BE GENERATED

**Where to find BOM in KiCad:**
1. Open your schematic in KiCad
2. Click **Tools → Generate Bill of Materials**
3. Choose a BOM plugin or export to CSV
4. Recommended: Use "bom_csv_grouped_by_value" for assembly

**Alternative: Command line export**
```bash
# From KiCad's Python console or script
import pcbnew
board = pcbnew.GetBoard()
# Export footprints and values
```

**What should be in your BOM:**
- Reference Designator (R1, C1, U1, etc.)
- Value (10kΩ, 0.1µF, ESP32-S3, etc.)
- Footprint (0805, SOT-23, QFN-48, etc.)
- Quantity
- Manufacturer Part Number (MPN)
- Supplier Part Number (Digi-Key, Mouser, LCSC, etc.)
- Description
- Notes (Do Not Place, etc.)

---

## NEXT STEPS - ACTION ITEMS

### Immediate (Before Layout)
1. **Answer the Design Requirements questions above**
2. **Generate BOM from KiCad** - verify all parts have footprints
3. **Run Electrical Rules Check (ERC)** in KiCad schematic
4. **Verify all critical circuits** using checklist above
5. **Add any missing circuits** (USB, load cell interface, etc.)
6. **Update schematic with proper values** for all resistors/capacitors

### Pre-Layout
7. **Assign footprints to all components**
8. **Verify footprints exist in your libraries**
9. **Create custom footprints** if needed (antenna, connectors)
10. **Define PCB stackup** (you have 4-layer - define layer usage)
11. **Set design rules** (trace width, clearance, via sizes)

### Ready for Layout
12. **Import netlist to PCB**
13. **Place critical components first** (ESP32, connectors, sensors)
14. **Route power planes** (GND, 3.3V)
15. **Route critical traces** (antenna, high-speed signals)
16. **Finish routing**
17. **DRC (Design Rule Check)**
18. **Generate Gerbers for manufacturing**

---

## QUESTIONS FOR YOU

Based on your PWA application integrating ESP32s with cell phones:

1. **What data are you collecting** from the "air scales"? Weight? Environmental? only GPS, ambient air pressure, ambient air temperature, main air sensor 0-150 psi. 
2. **How many ESP32 nodes** will communicate with one phone? 1
3. **Communication method:** BLE, WiFi Direct, or WiFi to server? 
4. **Offline data storage:** How much? SD card needed? 
5. **Power source:** Will these be battery-powered scales or always plugged in?
6. **Update frequency:** How often does each scale send data?

---

## FILES TO PROVIDE (When Ready)

- [ ] Completed Design Requirements (above)
- [ ] GPIO assignment spreadsheet
- [ ] BOM export from KiCad
- [ ] Any mechanical drawings or constraints
- [ ] Application architecture diagram (ESP32 ↔ Phone ↔ Server)

---

**Let me know what questions you can answer now, and I'll help verify your schematic design!**
