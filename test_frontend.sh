#!/bin/bash
echo "=== PRUEBA REAL FRONTEND ==="

# 1. Login como JUNIOR (user_id 428)
echo -e "\n1. Haciendo login..."
LOGIN=$(curl -s -X POST "https://netplay.com.co/api/login" \
  -H "Content-Type: application/json" \
  -d '{"username": "efew", "password": "123456"}' 2>&1)

TOKEN=$(echo $LOGIN | grep -oP '"access_token"\s*:\s*"\K[^"]+' | head -1)
echo "Token obtenido: ${TOKEN:0:50}..."

if [ -z "$TOKEN" ]; then
  echo "❌ No se pudo obtener token"
  exit 1
fi

# 2. Enviar ubicación a /my-location
echo -e "\n2. Enviando ubicación a /my-location..."
UPDATE=$(curl -s -X PUT "https://netplay.com.co/api/employees/my-location" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"latitude": 11.0234, "longitude": -74.8437, "user_id": "428"}' 2>&1)

echo "Respuesta: $UPDATE"

# 3. Verificar en BD
echo -e "\n3. Verificando en base de datos..."
php artisan tinker --execute="
\$emp = App\Models\Employee::find(2);
echo 'Empleado: ' . \$emp->first_name . ' ' . \$emp->last_name . PHP_EOL;
echo 'Lat: ' . \$emp->latitude . ', Lng: ' . \$emp->longitude . PHP_EOL;
echo 'Updated: ' . \$emp->last_location_update . PHP_EOL;
"

echo -e "\n=== FIN PRUEBA ==="
