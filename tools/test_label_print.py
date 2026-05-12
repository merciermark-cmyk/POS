"""Simple test print on Epson TM-L90 via serial COM3."""
import serial
import time

PORT = 'COM3'
BAUD = 19200

try:
    ser = serial.Serial(PORT, baudrate=BAUD, bytesize=8, parity='N', stopbits=1, timeout=2)
    print(f"Connected to {PORT} at {BAUD} baud")
    time.sleep(0.5)

    # Initialize
    ser.write(b'\x1b\x40')  # ESC @
    time.sleep(0.3)

    # Center align
    ser.write(b'\x1b\x61\x01')  # ESC a 1

    # Bold on
    ser.write(b'\x1b\x45\x01')  # ESC E 1
    ser.write(b'GRANVILLE ISLAND TEA\n')
    ser.write(b'\x1b\x45\x00')  # Bold off

    ser.write(b'--- Label Printer Test ---\n')
    ser.write(b'\n')

    # Left align
    ser.write(b'\x1b\x61\x00')  # ESC a 0
    ser.write(b'Printer: Epson TM-L90 Plus\n')
    ser.write(b'Port: COM3 (serial)\n')
    ser.write(b'Paper: 58mm linerless\n')
    ser.write(b'\n')

    # Center
    ser.write(b'\x1b\x61\x01')
    ser.write(b'If you can read this,\n')
    ser.write(b'the printer is working!\n')
    ser.write(b'\n')

    # Feed and cut
    ser.write(b'\x1d\x56\x42\x03')  # GS V — partial cut with 3 lines feed
    time.sleep(0.5)

    ser.close()
    print("Test sent! Check the printer for output.")

except serial.SerialException as e:
    print(f"ERROR: {e}")
except Exception as e:
    print(f"ERROR: {e}")
