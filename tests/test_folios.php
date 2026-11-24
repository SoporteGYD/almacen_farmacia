<?php
// Prueba sintactica y de coincidencia de consecutivo, NO se conecta a BD.
$validos = ['FAC-2','FAC-02','FAC-000002','FAC-0000002'];
$invalidos = ['FAC-0000003','FAC-0000012','FACT-0002','FAC-000002-OLD','FAC-'];
foreach ($validos as $folio) {
    if (preg_match('/^FAC-0*2$/D', $folio) !== 1) throw new RuntimeException("No aceptado: $folio");
}
foreach ($invalidos as $folio) {
    if (preg_match('/^FAC-0*2$/D', $folio) === 1) throw new RuntimeException("Se acepto un incorrecto: $folio");
}
echo "OK - folios validos e invalidos\n";
