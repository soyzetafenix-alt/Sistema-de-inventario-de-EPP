
# 📦 Sistema Web – Instalación Completa (PHP + XAMPP + PostgreSQL 16)

Este documento está pensado para personas SIN conocimientos técnicos.  
Incluye instrucciones para descargar el proyecto desde GitHub (ZIP), instalar XAMPP y PostgreSQL, importar la base de datos usando pgAdmin o --si falla-- usando CMD, y cómo cambiar el archivo `conexiones.php` para que el proyecto se conecte a la base de datos.

---

## 🎯 Resumen rápido (qué necesitas)

- XAMPP (para ejecutar Apache / PHP)
- PostgreSQL 16 (servidor de base de datos)
- pgAdmin 4 (administrador gráfico de PostgreSQL)
- Visual Studio Code (opcional, para ver archivos)
- El archivo del proyecto (descargado desde GitHub como ZIP)

---

## 1️⃣ Descargar el proyecto desde GitHub (método sencillo)

1. Abrir el enlace del repositorio en GitHub (tú lo compartes con ellos).  
2. Hacer clic en el botón verde **Code**.  
3. Seleccionar **Download ZIP**.  
4. Esperar a que se descargue el archivo y extraerlo (botón derecho → Extraer todo).

---

## 2️⃣ Instalar XAMPP

- Descarga oficial (Apache Friends):  
  https://www.apachefriends.org/es/index.html

- Tutorial en YouTube (buscar):  
  https://www.youtube.com/results?search_query=como+instalar+xampp+en+windows

---

## 3️⃣ Instalar PostgreSQL 16

- Descarga oficial:  
  https://www.postgresql.org/download/windows/

- Tutorial en YouTube (buscar):  
  https://www.youtube.com/results?search_query=como+instalar+postgresql+16+en+windows

> ⚠️ Durante la instalación de PostgreSQL recuerde la contraseña que coloque para el usuario `postgres`. La necesitará luego.

---

## 4️⃣ Mover el proyecto a la carpeta de XAMPP

1. Extraer la carpeta del proyecto descargada.  
2. Copiar la carpeta dentro de `C:\xampp\htdocs\` (ejemplo: `C:\xampp\htdocs\mi-proyecto`).

---

## 5️⃣ Iniciar Apache (XAMPP)

1. Abrir **XAMPP Control Panel**.  
2. Presionar **Start** en **Apache**.  
   (No es necesario MySQL si vas a usar PostgreSQL)

---

## 6️⃣ Crear la base de datos en pgAdmin (forma normal)

1. Abrir **pgAdmin 4**.  
2. Click derecho en **Databases** → **Create** → **Database**.  
3. Poner un nombre (ejemplo: `proyecto_db`) y guardar.

### Importar `.sql` desde pgAdmin
1. Seleccionar la base creada.  
2. Abrir **Query Tool**.  
3. Hacer clic en **Open File** y seleccionar el archivo `.sql`.  
4. Presionar **Execute** (botón ▶).  
Si funciona, las tablas y datos se crean automáticamente.

---

## 7️⃣ Método Alternativo – Importar la base de datos con CMD (si falla en pgAdmin)

Si al importar con pgAdmin aparece error (por ejemplo por formato *plain*), seguir este método:

### Abrir CMD
1. Presionar `Windows + R`.  
2. Escribir `cmd` y Enter.

### Ir a la carpeta donde está el archivo `.sql`
Ejemplo (si el archivo está en Descargas):
```
cd C:\Users\SuUsuario\Downloads
```
(Modificar según la ruta real)

### Ejecutar el comando de importación
```
psql -U postgres -d proyecto_db -f archivo.sql
```
- Reemplazar `proyecto_db` por el nombre de la base creada.  
- Reemplazar `archivo.sql` por el nombre real del archivo.  
- El sistema pedirá la contraseña del usuario `postgres`.  

Si aparece el error `psql no se reconoce como comando`, ejecutar con la ruta completa (ejemplo típico):
```
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U postgres -d proyecto_db -f archivo.sql
```
(la carpeta puede variar según la instalación)

### Verificación
1. Abrir pgAdmin 4.  
2. Refrescar la base y verificar que las tablas aparezcan.

---

## 8️⃣ Cambiar `conexiones.php` para conectar con la base de datos

En el proyecto podría haber archivos llamados `config.php`, `conexiones.php` o `connections.php`. Aquí hay dos ejemplos: **usando `pg_connect`** y **usando PDO**. Copia el que tu proyecto necesite y pégalo en el archivo `conexiones.php` (o edita el existente).

### Opción A — `conexiones.php` con `pg_connect` (sencillo)

```php
<?php
// conexiones.php - usando pg_connect (PostgreSQL)
$host = 'localhost';
$port = '5432';
$dbname = 'proyecto_db';
$user = 'postgres';
$password = 'TU_CONTRASENA';

$conn_string = "host={$host} port={$port} dbname={$dbname} user={$user} password={$password}";
$dbconn = pg_connect($conn_string);

if (!$dbconn) {
    // Mensaje simple amigable para quien no sabe de programación
    die("Error: no se pudo conectar a la base de datos. Verifique usuario, contraseña y que PostgreSQL esté activo.");
}
?>
```

### Opción B — `conexiones.php` con PDO (recomendado, moderno)

```php
<?php
// conexiones.php - usando PDO (PostgreSQL)
$host = 'localhost';
$port = '5432';
$dbname = 'proyecto_db';
$user = 'postgres';
$password = 'TU_CONTRASENA';

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname};";

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    // $pdo está listo para usarse en el proyecto
} catch (PDOException $e) {
    die("Error: no se pudo conectar a la base de datos. Mensaje: " . $e->getMessage());
}
?>
```

> Reemplaza `TU_CONTRASENA` por la contraseña real que usaron al instalar PostgreSQL.

---

## 9️⃣ Asegúrate de tener habilitada la extensión `pgsql` en PHP

1. Abrir: `C:\xampp\php\php.ini`  
2. Buscar y descomentar (quitar `;`) si están así:
```
extension=pgsql
extension=pdo_pgsql
```
3. Guardar y reiniciar Apache desde XAMPP Control Panel.

---

## 🔎 10️⃣ Ejecutar el sistema en el navegador

Abrir el navegador e ingresar:
```
http://localhost/mi-proyecto
```
(cambiar `mi-proyecto` por el nombre real de la carpeta que copiaste)

---

## 🚨 Problemas comunes y soluciones rápidas

- **No conecta a la base**: Verificar que PostgreSQL esté activo, que el puerto sea 5432 y que usuario/contraseña sean correctos.  
- **psql no se reconoce**: Usar la ruta completa a `psql.exe` como se muestra arriba.  
- **Error de extensión pgsql**: Habilitar `extension=pgsql` en `php.ini` y reiniciar Apache.  
- **El archivo .sql es muy grande o da errores**: Usar el método CMD con `psql` que suele funcionar mejor para archivos en formato *plain*.

---

## 📝 Estado del proyecto (rápido)

- ✅ Conexión local con BD (si configurado)  
- ✅ Registro de usuarios y operaciones principales básicas  
- 🚧 Pendiente: deploy en servidor online, dominio y manual final para usuarios

---

## 📎 Enlaces útiles

- XAMPP: https://www.apachefriends.org/es/index.html  
- PostgreSQL: https://www.postgresql.org/download/windows/  
- Buscar tutorial XAMPP en YouTube: https://www.youtube.com/results?search_query=como+instalar+xampp+en+windows  
- Buscar tutorial PostgreSQL 16 en YouTube: https://www.youtube.com/results?search_query=como+instalar+postgresql+16+en+windows

---


