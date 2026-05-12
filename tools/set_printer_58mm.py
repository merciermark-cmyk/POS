"""
Set Epson TM-L90 to 58mm paper width via ESC/POS memory switch command.
Also prints a short test line to confirm.
"""
import serial
import time

PORT = 'COM3'
BAUD = 19200

ser = serial.Serial(PORT, baudrate=BAUD, bytesize=8, parity='N', stopbits=1, timeout=2)
time.sleep(0.5)

# Initialize printer
ser.write(b'\x1b\x40')  # ESC @ — initialize
time.sleep(0.3)

# GS ( E — Set memory switch
# Paper width setting: memory switch #1, bit 2
# For 58mm: set memory switch address 1 to value with 58mm bit
# Command: GS ( E pL pH fn a d1 d2
# fn=6 (set customized setting values)
# The exact memory switch layout varies by firmware, so we try the standard approach:

# Method 1: GS ( E — Set paper width via customized settings
# Address 0x01 = paper width, data = 0x00 for 58mm
ser.write(b'\x1d\x28\x45\x03\x00\x06\x01\x30')  # Try setting paper width to 58mm
time.sleep(0.3)

# Method 2: Alternative memory switch command
# GS ( E pL pH fn m a msb lsb
ser.write(b'\x1d\x28\x45\x04\x00\x05\x01\x00\x02')  # Paper width = 58mm
time.sleep(0.3)

# Print test
ser.write(b'\x1b\x40')  # Re-initialize
time.sleep(0.2)
ser.write(b'Paper width test - 58mm\n')
ser.write(b'If this prints correctly,\n')
ser.write(b'the setting worked!\n')
ser.write(b'\x1d\x56\x42\x03')  # GS V — partial cut with feed
time.sleep(0.5)

ser.close()
print("Done. Check printer output.")
print("NOTE: You may need to power-cycle the printer for the memory switch to take effect.")
