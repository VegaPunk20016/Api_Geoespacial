# Api Geoespacial DENUE/INE

Esta Api esta construida con una arquitectura Monolítica Modular.

*Se implementa Clean Architecture y singleton pattern en cada Modulo para una mejor estructura de trabajo.*

Se recomienda utilizar las migraciones y seeds que están en cada modulo para una mejor integration de datos.

# php spark migrate --all
# php spark db:seed "Modules\Users\Database\Seeds\SecuritySeeder"

Se recomienda leer la documentation para entender mejor el funcionamiento de la api y los endpoints.