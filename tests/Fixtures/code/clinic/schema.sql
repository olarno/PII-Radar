CREATE TABLE patients (
    id INT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    courriel VARCHAR(255) NULL
);

CREATE TABLE ville_reference (
    id INT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL
);
