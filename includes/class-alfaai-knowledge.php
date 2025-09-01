<?php
if (!defined('ABSPATH')) {
    exit('Direct access forbidden.');
}

class AlfaAI_Knowledge {
    
    public static function init() {
        // Initialize knowledge base
    }
    
    public static function search_alfassa_info($query) {
        $query = strtolower($query);
        
        // Database Alfassa - Informazioni sui fondatori
        $alfassa_data = array(
            'gianni_diotallevi' => array(
                'name' => 'Gianni Diotallevi',
                'role' => 'Fondatore di ALFASSA',
                'description' => 'Gianni Diotallevi è il fondatore di ALFASSA, un\'azienda innovativa nel settore tecnologico.',
                'image_url' => 'https://alfassa.org/wp-content/uploads/2023/01/gianni-diotallevi.jpg',
                'bio' => 'Imprenditore visionario con oltre 20 anni di esperienza nel settore tecnologico. Ha fondato ALFASSA con l\'obiettivo di creare soluzioni innovative per il futuro.',
                'keywords' => array('gianni', 'diotallevi', 'fondatore', 'alfassa', 'ceo', 'presidente')
            ),
            'antonio_mastrangelo' => array(
                'name' => 'Antonio Mastrangelo',
                'role' => 'Co-Founder ALFASSA & Network Manager',
                'description' => 'Antonio Mastrangelo è co-fondatore di ALFASSA e Network Manager.',
                'image_url' => 'https://alfassa.org/wp-content/uploads/2023/01/antonio-mastrangelo.jpg',
                'bio' => 'Esperto in networking e infrastrutture tecnologiche, co-fondatore di ALFASSA.',
                'keywords' => array('antonio', 'mastrangelo', 'co-founder', 'network', 'manager')
            ),
            'tabatabaei_soroush' => array(
                'name' => 'Tabatabaei Soroush',
                'role' => 'Interdisciplinary Manager',
                'description' => 'Tabatabaei Soroush è Interdisciplinary Manager presso ALFASSA.',
                'image_url' => 'https://alfassa.org/wp-content/uploads/2023/01/tabatabaei-soroush.jpg',
                'bio' => 'Manager interdisciplinare con competenze trasversali in diversi settori tecnologici.',
                'keywords' => array('tabatabaei', 'soroush', 'manager', 'interdisciplinary')
            ),
            'salvatore_pappalardo' => array(
                'name' => 'Salvatore Pappalardo',
                'role' => 'Co-founder of Alfassa',
                'description' => 'Salvatore Pappalardo è co-fondatore di ALFASSA.',
                'image_url' => 'https://alfassa.org/wp-content/uploads/2023/01/salvatore-pappalardo.jpg',
                'bio' => 'Co-fondatore di ALFASSA con esperienza nel settore tecnologico e dell\'innovazione.',
                'keywords' => array('salvatore', 'pappalardo', 'co-founder', 'alfassa')
            )
        );
        
        // Cerca corrispondenze
        foreach ($alfassa_data as $person_id => $person) {
            foreach ($person['keywords'] as $keyword) {
                if (strpos($query, $keyword) !== false) {
                    return $person;
                }
            }
        }
        
        // Informazioni generali su ALFASSA
        if (strpos($query, 'alfassa') !== false || strpos($query, 'azienda') !== false) {
            return array(
                'name' => 'ALFASSA',
                'description' => 'ALFASSA è un\'azienda innovativa nel settore tecnologico fondata da Gianni Diotallevi.',
                'website' => 'https://alfassa.org',
                'founded' => 'Fondata da Gianni Diotallevi',
                'team' => 'Team composto da professionisti esperti nel settore tecnologico'
            );
        }
        
        return null;
    }
    
    public static function format_person_response($person_data) {
        if (!$person_data) {
            return null;
        }
        
        $response = "**{$person_data['name']}**\n\n";
        $response .= "**Ruolo:** {$person_data['role']}\n\n";
        $response .= "{$person_data['description']}\n\n";
        
        if (isset($person_data['bio'])) {
            $response .= "**Biografia:** {$person_data['bio']}\n\n";
        }
        
        if (isset($person_data['image_url'])) {
            $response .= "![{$person_data['name']}]({$person_data['image_url']})\n\n";
        }
        
        return $response;
    }
    
    public static function get_alfassa_team() {
        return array(
            array(
                'name' => 'Gianni Diotallevi',
                'role' => 'Fondatore di ALFASSA',
                'image' => 'https://alfassa.org/wp-content/uploads/2023/01/gianni-diotallevi.jpg'
            ),
            array(
                'name' => 'Antonio Mastrangelo',
                'role' => 'Co-Founder ALFASSA & Network Manager',
                'image' => 'https://alfassa.org/wp-content/uploads/2023/01/antonio-mastrangelo.jpg'
            ),
            array(
                'name' => 'Tabatabaei Soroush',
                'role' => 'Interdisciplinary Manager',
                'image' => 'https://alfassa.org/wp-content/uploads/2023/01/tabatabaei-soroush.jpg'
            ),
            array(
                'name' => 'Salvatore Pappalardo',
                'role' => 'Co-founder of Alfassa',
                'image' => 'https://alfassa.org/wp-content/uploads/2023/01/salvatore-pappalardo.jpg'
            )
        );
    }
}

