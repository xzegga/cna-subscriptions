<?php
/**
 * Helper de Ubicaciones de El Salvador
 * Proporciona datos de Departamentos, Municipios y Distritos
 *
 * @package CNA_Subscriptions
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CNA_Locations_Helper {

    /**
     * Obtiene la lista de países disponibles
     *
     * @return array
     */
    public function get_countries() {
        return array('El Salvador');
    }

    /**
     * Obtiene la lista de departamentos
     *
     * @param string $country País (por defecto El Salvador)
     * @return array
     */
    public function get_departments($country = 'El Salvador') {
        $locations = $this->get_all_locations();
        if (isset($locations[$country])) {
            return array_keys($locations[$country]);
        }
        return array();
    }

    /**
     * Obtiene los municipios de un departamento
     *
     * @param string $department
     * @param string $country País (por defecto El Salvador)
     * @return array
     */
    public function get_municipalities($department, $country = 'El Salvador') {
        $locations = $this->get_all_locations();
        if (isset($locations[$country][$department])) {
            return array_keys($locations[$country][$department]);
        }
        return array();
    }

    /**
     * Obtiene los distritos de un municipio
     *
     * @param string $department
     * @param string $municipality
     * @param string $country País (por defecto El Salvador)
     * @return array
     */
    public function get_districts($department, $municipality, $country = 'El Salvador') {
        $locations = $this->get_all_locations();
        if (isset($locations[$country][$department][$municipality])) {
            return $locations[$country][$department][$municipality];
        }
        return array();
    }

    /**
     * Obtiene toda la estructura de ubicaciones
     * Estructura: [País][Departamento][Municipio] => [Distrito1, Distrito2, ...]
     * 
     * Datos oficiales de El Salvador según Asamblea Legislativa
     * Fuente: https://www.asamblea.gob.sv/node/12806
     * 14 departamentos, 44 municipios, 262 distritos
     *
     * @return array
     */
    public function get_all_locations() {
        return array(
            'El Salvador' => array(
                'Ahuachapán' => array(
                    'Ahuachapán Norte' => array('Atiquizaya', 'El Refugio', 'San Lorenzo', 'Turín'),
                    'Ahuachapán Centro' => array('Ahuachapán', 'Apaneca', 'Concepción de Ataco', 'Tacuba'),
                    'Ahuachapán Sur' => array('Guaymango', 'Jujutla', 'San Francisco Menendez', 'San Pedro Puxtla'),
                ),
                'San Salvador' => array(
                    'San Salvador Norte' => array('Aguilares', 'El Paisnal', 'Guazapa'),
                    'San Salvador Oeste' => array('Apopa', 'Nejapa'),
                    'San Salvador Este' => array('Ilopango', 'San Martín', 'Soyapango', 'Tonacatepeque'),
                    'San Salvador Centro' => array('Ayutuxtepeque', 'Mejicanos', 'San Salvador', 'Cuscatancingo', 'Ciudad Delgado'),
                    'San Salvador Sur' => array('Panchimalco', 'Rosario de Mora', 'San Marcos', 'Santo Tomás', 'Santiago Texacuangos'),
                ),
                'La Libertad' => array(
                    'La Libertad Norte' => array('Quezaltepeque', 'San Matías', 'San Pablo Tacachico'),
                    'La Libertad Centro' => array('San Juan Opico', 'Ciudad Arce'),
                    'La Libertad Oeste' => array('Colón', 'Jayaque', 'Sacacoyo', 'Tepecoyo', 'Talnique'),
                    'La Libertad Este' => array('Antiguo Cuscatlán', 'Huizucar', 'Nuevo Cuscatlán', 'San José Villanueva', 'Zaragoza'),
                    'La Libertad Costa' => array('Chiltuipán', 'Jicalapa', 'La Libertad', 'Tamanique', 'Teotepeque'),
                    'La Libertad Sur' => array('Comasagua', 'Santa Tecla'),
                ),
                'Chalatenango' => array(
                    'Chalatenango Norte' => array('La Palma', 'Citalá', 'San Ignacio'),
                    'Chalatenango Centro' => array('Nueva Concepción', 'Tejutla', 'La Reina', 'Agua Caliente', 'Dulce Nombre de María', 'El Paraíso', 'San Francisco Morazán', 'San Rafael', 'Santa Rita', 'San Fernando'),
                    'Chalatenango Sur' => array('Chalatenango', 'Arcatao', 'Azacualpa', 'Comalapa', 'Concepción Quezaltepeque', 'El Carrizal', 'La Laguna', 'Las Vueltas', 'Nombre de Jesús', 'Nueva Trinidad', 'Ojos de Agua', 'Potonico', 'San Antonio de La Cruz', 'San Antonio Los Ranchos', 'San Francisco Lempa', 'San Isidro Labrador', 'San José Cancasque', 'San Miguel de Mercedes', 'San José Las Flores', 'San Luis del Carmen'),
                ),
                'Cuscatlán' => array(
                    'Cuscatlán Norte' => array('Suchitoto', 'San José Guayabal', 'Oratorio de Concepción', 'San Bartolomé Perulapán', 'San Pedro Perulapán'),
                    'Cuscatlán Sur' => array('Cojutepeque', 'San Rafael Cedros', 'Candelaria', 'Monte San Juan', 'El Carmen', 'San Cristóbal', 'Santa Cruz Michapa', 'San Ramón', 'El Rosario', 'Santa Cruz Analquito', 'Tenancingo'),
                ),
                'Cabañas' => array(
                    'Cabañas Este' => array('Sensuntepeque', 'Victoria', 'Dolores', 'Guacotecti', 'San Isidro'),
                    'Cabañas Oeste' => array('Ilobasco', 'Tejutepeque', 'Jutiapa', 'Cinquera'),
                ),
                'La Paz' => array(
                    'La Paz Oeste' => array('Cuyultitán', 'Olocuilta', 'San Juan Talpa', 'San Luis Talpa', 'San Pedro Masahuat', 'Tapalhuaca', 'San Francisco Chinameca'),
                    'La Paz Centro' => array('El Rosario', 'Jerusalén', 'Mercedes La Ceiba', 'Paraíso de Osorio', 'San Antonio Masahuat', 'San Emigdio', 'San Juan Tepezontes', 'San Luis La Herradura', 'San Miguel Tepezontes', 'San Pedro Nonualco', 'Santa María Ostuma', 'Santiago Nonualco'),
                    'La Paz Este' => array('San Juan Nonualco', 'San Rafael Obrajuelo', 'Zacatecoluca'),
                ),
                'La Unión' => array(
                    'La Unión Norte' => array('Anamorós', 'Bolivar', 'Concepción de Oriente', 'El Sauce', 'Lislique', 'Nueva Esparta', 'Pasaquina', 'Polorós', 'San José La Fuente', 'Santa Rosa de Lima'),
                    'La Unión Sur' => array('Conchagua', 'El Carmen', 'Intipucá', 'La Unión', 'Meanguera del Golfo', 'San Alejo', 'Yayantique', 'Yucuaiquín'),
                ),
                'Usulután' => array(
                    'Usulután Norte' => array('Santiago de María', 'Alegría', 'Berlín', 'Mercedes Umana', 'Jucuapa', 'El Triunfo', 'Estanzuelas', 'San Buenaventura', 'Nueva Granada'),
                    'Usulután Este' => array('Usulután', 'Jucuarán', 'San Dionisio', 'Concepción Batres', 'Santa María', 'Ozatlán', 'Tecapán', 'Santa Elena', 'California', 'Ereguayquín'),
                    'Usulután Oeste' => array('Jiquilisco', 'Puerto El Triunfo', 'San Agustín', 'San Francisco Javier'),
                ),
                'Sonsonate' => array(
                    'Sonsonate Norte' => array('Juayúa', 'Nahuizalco', 'Salcoatitán', 'Santa Catarina Masahuat'),
                    'Sonsonate Centro' => array('Sonsonate', 'Sonzacate', 'Nahulingo', 'San Antonio del Monte', 'Santo Domingo de Guzmán'),
                    'Sonsonate Este' => array('Izalco', 'Armenia', 'Caluco', 'San Julián', 'Cuisnahuat', 'Santa Isabel Ishuatán'),
                    'Sonsonate Oeste' => array('Acajutla'),
                ),
                'Santa Ana' => array(
                    'Santa Ana Norte' => array('Masahuat', 'Metapán', 'Santa Rosa Guachipilín', 'Texistepeque'),
                    'Santa Ana Centro' => array('Santa Ana'),
                    'Santa Ana Este' => array('Coatepeque', 'El Congo'),
                    'Santa Ana Oeste' => array('Candelaria de la Frontera', 'Chalchuapa', 'El Porvenir', 'San Antonio Pajonal', 'San Sebastián Salitrillo', 'Santiago de La Frontera'),
                ),
                'San Vicente' => array(
                    'San Vicente Norte' => array('Apastepeque', 'Santa Clara', 'San Ildefonso', 'San Esteban Catarina', 'San Sebastián', 'San Lorenzo', 'Santo Domingo'),
                    'San Vicente Sur' => array('San Vicente', 'Guadalupe', 'Verapaz', 'Tepetitán', 'Tecoluca', 'San Cayetano Istepeque'),
                ),
                'San Miguel' => array(
                    'San Miguel Norte' => array('Ciudad Barrios', 'Sesori', 'Nuevo Edén de San Juan', 'San Gerardo', 'San Luis de La Reina', 'Carolina', 'San Antonio del Mosco', 'Chapeltique'),
                    'San Miguel Centro' => array('San Miguel', 'Comacarán', 'Uluazapa', 'Moncagua', 'Quelepa', 'Chirilagua'),
                    'San Miguel Oeste' => array('Chinameca', 'Nueva Guadalupe', 'Lolotique', 'San Jorge', 'San Rafael Oriente', 'El Tránsito'),
                ),
                'Morazán' => array(
                    'Morazán Norte' => array('Arambala', 'Cacaopera', 'Corinto', 'El Rosario', 'Joateca', 'Jocoaitique', 'Meanguera', 'Perquín', 'San Fernando', 'San Isidro', 'Torola'),
                    'Morazán Sur' => array('Chilanga', 'Delicias de Concepción', 'El Divisadero', 'Gualococti', 'Guatajiagua', 'Jocoro', 'Lolotiquillo', 'Osicala', 'San Carlos', 'San Francisco Gotera', 'San Simón', 'Sensembra', 'Sociedad', 'Yamabal', 'Yoloaiquín'),
                ),
            ),
        );
    }

    /**
     * Retorna los datos como JSON (para API REST)
     *
     * @return string
     */
    public function get_json() {
        return json_encode($this->get_all_locations());
    }

    /**
     * Busca en qué zona está un distrito específico
     *
     * @param string $department
     * @param string $municipality
     * @param string $district
     * @param string $country País (por defecto El Salvador)
     * @return int|null Zone ID o null si no se encuentra
     */
    public function find_zone_by_location($department, $municipality, $district, $country = 'El Salvador') {
        global $wpdb;
        $table_prefix = $wpdb->prefix;

        $zone_id = $wpdb->get_var($wpdb->prepare(
            "SELECT zone_id FROM {$table_prefix}cna_shipping_locations 
             WHERE country = %s AND department = %s AND municipality = %s AND district = %s 
             LIMIT 1",
            $country,
            $department,
            $municipality,
            $district
        ));

        return $zone_id ? intval($zone_id) : null;
    }
}
