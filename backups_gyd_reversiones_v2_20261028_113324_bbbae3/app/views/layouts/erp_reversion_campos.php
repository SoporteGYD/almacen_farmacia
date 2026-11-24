<?php /** Campos comunes para cancelar o devolver. No contiene lógica de negocio. */ ?>
<label>Motivo (obligatorio)</label>
<textarea name="motivo" required minlength="8" maxlength="500" placeholder="Explica el motivo y confirma lo que devolvió el cliente"></textarea>
<label>Medio del reembolso REALIZADO</label><select name="medio" required>
 <option value="">Seleccionar medio...</option><option value="EFECTIVO">Efectivo entregado</option><option value="TARJETA">Tarjeta (devolución en terminal confirmada)</option><option value="TRANSFERENCIA">Transferencia realizada</option><option value="OTRO">Otro / combinación de medios</option>
</select>
<label>Referencia del reembolso (obligatoria excepto efectivo)</label><input name="referencia" maxlength="120" placeholder="Folio del banco, autorización o detalle del pago">
<label class="check"><input type="checkbox" name="fisico_confirmado" value="1" required> Confirmo que la mercancía a reincorporar está físicamente aquí, fue contada y está APTA para la venta; si es una solicitud de Almacén pendiente, confirmo que no fue entregada.</label>
<label class="check"><input type="checkbox" name="reembolso_confirmado" value="1" required> Confirmo que el dinero YA fue reembolsado por el medio seleccionado (el ERP no realiza operaciones bancarias).</label>
