-- Clinique Bonsoir - schema (MariaDB)
-- Ecrit en 2014, jamais revise depuis.

SET NAMES utf8mb4;

CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prenom VARCHAR(100) NOT NULL,
    nom VARCHAR(100) NOT NULL,
    date_naissance DATE NOT NULL,
    nam VARCHAR(12) NOT NULL,
    courriel VARCHAR(255) NULL,
    telephone VARCHAR(20) NOT NULL,
    adresse VARCHAR(255) NULL,
    ville VARCHAR(100) NULL,
    code_postal VARCHAR(7) NULL,
    cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom_utilisateur VARCHAR(50) NOT NULL UNIQUE,
    courriel VARCHAR(255) NOT NULL,
    mot_de_passe CHAR(32) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'reception',
    derniere_ip VARCHAR(45) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rendezvous (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    medecin_id INT NOT NULL,
    date_heure DATETIME NOT NULL,
    motif TEXT NOT NULL,
    statut VARCHAR(20) NOT NULL DEFAULT 'prevu',
    rappel_envoye TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (patient_id) REFERENCES patients(id),
    FOREIGN KEY (medecin_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE paiements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    montant DECIMAL(8,2) NOT NULL,
    carte_4_derniers CHAR(4) NOT NULL,
    titulaire_carte VARCHAR(200) NOT NULL,
    paye_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE parametres_courriel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    sujet VARCHAR(255) NOT NULL,
    email_template TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ville_reference (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    region VARCHAR(100) NOT NULL,
    code_region CHAR(2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
