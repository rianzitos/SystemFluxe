"""Confere o contrato entre firmware e circuito Wokwi, sem executar a simulação."""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1] / 'iot/esp32_balanca'
diagram = json.loads((root / 'diagram.json').read_text())
source = (root / 'esp32_acesso.ino').read_text()
pins = {key: int(value) for key, value in re.findall(r'^#define\s+(\w+)\s+(\d+)', source, re.M)}
parts = {p['id']: p for p in diagram['parts']}
links = {frozenset(c[:2]) for c in diagram['connections']}
assert parts['esp']['type'] == 'wokwi-esp32-devkit-v1'
esp_pins = {'VIN','GND.1','GND.2','3V3','EN','VP','VN','RX0','TX0','RX2','TX2'} | {
    'D'+str(n) for n in [2,4,5,12,13,14,15,18,19,21,22,23,25,26,27,32,33,34,35]
}
allowed = {
    'wokwi-esp32-devkit-v1': esp_pins,
    'wokwi-hx711': {'VCC','GND','DT','SCK'},
    'wokwi-lcd1602': {'VCC','GND','SDA','SCL'},
    'wokwi-pushbutton': {'1.l','1.r','2.l','2.r'},
    'wokwi-led': {'A','C'},
    'wokwi-resistor': {'1','2'},
}
for link in diagram['connections']:
    for endpoint in link[:2]:
        component, pin = endpoint.split(':')
        if component == '$serialMonitor':
            assert pin in {'RX','TX'}
        else:
            assert pin in allowed[parts[component]['type']], endpoint

def linked(a, b):
    assert frozenset((a,b)) in links, (a,b)

def gpio(name):
    n = pins[name]
    return 'esp:' + {16:'RX2',17:'TX2'}.get(n,'D'+str(n))

for part, macro in [('btnTara','BTN_TARA'),('btnFim','BTN_FIM'),('btnRefeicao','BTN_REFEICAO')]:
    linked(part+':1.l',gpio(macro))
    linked(part+':2.l','esp:GND.1')
linked('cell1:DT',gpio('HX_DT_PIN'))
linked('cell1:SCK',gpio('HX_SCK_PIN'))
linked('lcd:SDA',gpio('LCD_SDA'))
linked('lcd:SCL',gpio('LCD_SCL'))
linked('lcd:VCC','esp:VIN')
linked('lcd:GND','esp:GND.1')
assert parts['lcd']['attrs']['pins'] == 'i2c'
address = int(re.search(r'LCD_ENDERECO\s*=\s*(0x[0-9a-fA-F]+)',source)[1],16)
assert int(parts['lcd']['attrs']['i2cAddress'],16) == address
signals = [pins[k] for k in ['HX_DT_PIN','HX_SCK_PIN','BTN_TARA','BTN_FIM','BTN_REFEICAO','LCD_SDA','LCD_SCL','LED_GREEN','LED_RED']]
assert len(set(signals)) == len(signals)
print('PASS: terminais Wokwi, GPIOs do firmware, 3 botões, HX711 e LCD I2C sem conflitos')
