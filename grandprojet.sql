-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Hôte : 127.0.0.1
-- Généré le : mar. 25 nov. 2025 à 16:39
-- Version du serveur : 10.4.21-MariaDB
-- Version de PHP : 7.3.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `grandprojet`
--

-- --------------------------------------------------------

--
-- Structure de la table `appeloffre`
--

CREATE TABLE `appeloffre` (
  `idApp` int(11) NOT NULL,
  `idPro` int(11) NOT NULL,
  `dateCreation` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `commission`
--

CREATE TABLE `commission` (
  `idCom` int(11) NOT NULL,
  `numCommission` varchar(10) NOT NULL,
  `dateCommission` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `document`
--

CREATE TABLE `document` (
  `idDoc` int(11) NOT NULL,
  `idPro` int(11) DEFAULT NULL,
  `libDoc` varchar(200) NOT NULL,
  `cheminAcces` varchar(400) NOT NULL,
  `type` int(11) NOT NULL,
  `idExterne` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `document`
--

INSERT INTO `document` (`idDoc`, `idPro`, `libDoc`, `cheminAcces`, `type`, `idExterne`) VALUES
(1, 6, 'التقرير الرقابي مشروع اقتناء 30 عربة مترو.', '../uploads/documents/doc_6_1764003257.pdf', 1, 6),
(2, 7, 'مشروع منصة أسواق الإنتاج الوسط', '../uploads/documents/doc_7_1764005866.pdf', 1, 7);

-- --------------------------------------------------------

--
-- Structure de la table `etablissement`
--

CREATE TABLE `etablissement` (
  `idEtablissement` int(11) NOT NULL,
  `libEtablissement` varchar(300) NOT NULL,
  `adrEtablissement` varchar(300) NOT NULL,
  `idMinistere` int(11) NOT NULL,
  `idGouvernement` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `etablissement`
--

INSERT INTO `etablissement` (`idEtablissement`, `libEtablissement`, `adrEtablissement`, `idMinistere`, `idGouvernement`) VALUES
(1, 'الهيئة الوطنية لحماية المعطيات الشخصية', '1 نهج محمد معلى 1002 ميتيال فيل تونس', 1, 5),
(2, 'الشركة الجديدة للطباعة والصحافة والنشر', '6 ، نهج علي باش حامبة 1000 تونس', 1, 5),
(3, 'وكالة تونس افريقيا للانباء', '7 شارع سليمان بن سليمان 2092 – المنار الثاني- تونس', 1, 5),
(4, 'المطبعة الرسمية للجمهورية التونسية', 'شارع فرحات حشاد - رادس المدينة 2098 تونس', 1, 7),
(5, 'الأرشيف الوطني التونسي', '122, Boulevard 9 avril 1938, Tunis', 1, 5),
(6, 'التلفزة التونسية', 'Rue de la Ligue Arabe, Belvédère - Tunis 1002\r\n', 1, 5),
(7, 'الإذاعة الوطنية', '71 شارع الحرية تونس', 1, 5),
(8, 'السجل الوطني للمؤسسات', 'ﻋـﺪد 1 ﻧـﻬـﺞ اﺑﻮ اﻟـﻌﺒﺎس اﻟﻘـﺴﻨـﻄﻴﻨﻲ اﻟﺤﻲ اﻻوﻟﻤﺒﻲ، ص.ب 66 - ﺗﻮﻧﺲ 1003 - ﺗﻮﻧﺲ', 1, 5),
(9, 'المجمع التونسي للعلوم والآداب والفنون -بيت الحكمة', 'المجمع التونسي للعلوم والآداب والفنون -بيت الحكمة', 1, 5),
(10, 'المركز الوطني للتوثيق', 'نهج عبد الرزاق الشرايبي 1000 تونس', 1, 5),
(11, 'مركز إفادة	', '66 شارع معاوية إبن ابي سفيان -2037- المنزه السابع\r\n', 1, 5),
(12, 'المدرسة الوطنية للإدارة', '24 نهج الدكتور كلمات ميتيال فيل تونس', 1, 5),
(13, 'المركز الأفريقي لتدريب الصحفيين والاتصال	', '9 نهج هوكر دوليتل 1002 تونس بلفيدار - تونس', 1, 5),
(15, 'الوزارة', '', 17, 5),
(33, 'الوزارة', '', 11, 5),
(34, 'الوزارة', '', 7, 5),
(35, 'الوزارة', '', 15, 5),
(36, 'الوزارة', '', 8, 5),
(37, 'الوزارة', '', 9, 5),
(38, 'الوزارة', '', 2, 5),
(39, 'الوزارة', '', 3, 5),
(40, 'الوزارة', '', 4, 5),
(41, 'الوزارة', '', 5, 5),
(42, 'الوزارة', '', 6, 5),
(43, 'الوزارة', '', 10, 5),
(44, 'الوزارة', '', 12, 5),
(45, 'الوزارة', '', 13, 5),
(46, 'الوزارة', '', 14, 5),
(47, 'الوزارة', '', 18, 5),
(48, 'الوزارة', '', 5, 5),
(49, 'الوزارة', '', 16, 5),
(50, 'الوزارة', '', 19, 5),
(51, 'الوزارة', '', 20, 5),
(52, 'الوزارة', '', 21, 5),
(53, 'الوزارة', '', 22, 5),
(54, 'الوزارة', '', 23, 5),
(55, 'الوزارة', '', 24, 5),
(56, 'الوزارة', '', 25, 5),
(57, 'الوزارة', '', 5, 5);

-- --------------------------------------------------------

--
-- Structure de la table `fournisseur`
--

CREATE TABLE `fournisseur` (
  `idFour` int(11) NOT NULL,
  `nomFour` varchar(200) NOT NULL,
  `adresseFour` varchar(300) NOT NULL,
  `telFour` int(11) NOT NULL,
  `emailFour` varchar(200) NOT NULL,
  `rib` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `gouvernorat`
--

CREATE TABLE `gouvernorat` (
  `idGov` int(11) NOT NULL,
  `libGov` varchar(100) NOT NULL,
  `positionGov` varchar(100) NOT NULL,
  `idSecteur` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `gouvernorat`
--

INSERT INTO `gouvernorat` (`idGov`, `libGov`, `positionGov`, `idSecteur`) VALUES
(1, 'بنزرت', 'الشمال الغربي', 1),
(2, 'باجة', 'الشمال الغربي', 1),
(3, 'جندوبة', 'الشمال الغربي', 1),
(4, 'الكاف', 'الشمال الغربي', 1),
(5, 'تونس', 'تونس الكبرى', 2),
(6, 'أريانة', 'تونس الكبرى', 2),
(7, 'بن عروس', 'تونس الكبرى', 2),
(8, 'منوبة', 'تونس الكبرى', 2),
(9, 'زغوان', 'الساحل', 2),
(10, 'نابل', 'الساحل', 2),
(11, 'سليانة', 'الشمال الغربي', 3),
(12, 'سوسة', 'الساحل', 3),
(13, 'القصرين', 'الجنوب الغربي', 3),
(14, 'القيروان', 'الجنوب الغربي', 3),
(15, 'المنستير', 'الساحل', 3),
(16, 'المهدية', 'الساحل', 3),
(17, 'توزر', 'الجنوب الغربي', 4),
(18, 'سيدي بوزيد', 'الجنوب الغربي', 4),
(19, 'صفاقس', 'إقليم صفاقس', 4),
(20, 'قفصة', 'الجنوب الغربي', 4),
(21, 'تطاوين', 'الجنوب الشرقي', 5),
(22, 'قابس', 'الجنوب الشرقي', 5),
(23, 'قبلي', 'الجنوب الشرقي', 5),
(24, 'مدنين', 'الجنوب الشرقي', 5);

-- --------------------------------------------------------

--
-- Structure de la table `journal`
--

CREATE TABLE `journal` (
  `idJournal` int(11) NOT NULL,
  `idUser` int(11) NOT NULL,
  `action` varchar(400) NOT NULL,
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `journal`
--

INSERT INTO `journal` (`idJournal`, `idUser`, `action`, `date`) VALUES
(1, 8, 'تسجيل الدخول', '2025-11-21'),
(2, 8, 'تسجيل الخروج', '2025-11-21'),
(3, 8, 'تسجيل الدخول', '2025-11-21'),
(4, 8, 'تسجيل الخروج', '2025-11-21'),
(5, 8, 'تسجيل الدخول', '2025-11-21'),
(6, 8, 'تسجيل الخروج', '2025-11-21'),
(7, 8, 'تسجيل الدخول', '2025-11-21'),
(8, 8, 'تسجيل الخروج', '2025-11-21'),
(9, 8, 'تسجيل الدخول', '2025-11-21'),
(10, 8, 'تسجيل الخروج', '2025-11-21'),
(11, 8, 'تسجيل الدخول', '2025-11-21'),
(12, 8, 'تسجيل الخروج', '2025-11-21'),
(13, 8, 'تسجيل الدخول', '2025-11-21'),
(14, 8, 'تسجيل الخروج', '2025-11-21'),
(15, 1, 'تسجيل الدخول', '2025-11-21'),
(16, 1, 'تسجيل الخروج', '2025-11-21'),
(17, 1, 'تسجيل الدخول', '2025-11-21'),
(18, 1, 'تسجيل الدخول', '2025-11-24'),
(19, 1, 'تسجيل الدخول', '2025-11-24'),
(20, 34, 'تسجيل الخروج', '2025-11-24'),
(21, 1, 'تسجيل الدخول', '2025-11-24'),
(22, 1, 'تسجيل الخروج', '2025-11-24'),
(23, 1, 'تسجيل الدخول', '2025-11-24'),
(24, 1, 'تسجيل الدخول', '2025-11-24'),
(25, 34, 'تسجيل الخروج', '2025-11-24'),
(26, 1, 'تسجيل الدخول', '2025-11-24'),
(27, 1, 'تسجيل الدخول', '2025-11-24'),
(28, 1, 'تسجيل الدخول', '2025-11-24'),
(29, 34, 'تسجيل الخروج', '2025-11-24'),
(30, 1, 'تسجيل الدخول', '2025-11-24'),
(31, 34, 'تسجيل الخروج', '2025-11-24'),
(32, 1, 'تسجيل الدخول', '2025-11-24'),
(33, 34, 'تسجيل الخروج', '2025-11-24'),
(34, 1, 'تسجيل الدخول', '2025-11-24'),
(35, 1, 'إضافة مقترح جديد رقم 6: مشروع اقتناء 30 عربة مترو لف?', '2025-11-24'),
(36, 34, 'تسجيل الخروج', '2025-11-24'),
(37, 1, 'تسجيل الدخول', '2025-11-24'),
(38, 34, 'تسجيل الخروج', '2025-11-24'),
(39, 1, 'تسجيل الدخول', '2025-11-24'),
(40, 34, 'تسجيل الخروج', '2025-11-24'),
(41, 1, 'تسجيل الدخول', '2025-11-24'),
(42, 1, 'إضافة مقترح جديد رقم 7: مشروع منصة أسواق الإنتاج ال', '2025-11-24');

-- --------------------------------------------------------

--
-- Structure de la table `lot`
--

CREATE TABLE `lot` (
  `lidLot` int(11) NOT NULL,
  `sujetLot` varchar(300) NOT NULL,
  `idFournisseur` int(11) NOT NULL,
  `somme` float NOT NULL,
  `idAppelOffre` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `ministere`
--

CREATE TABLE `ministere` (
  `idMinistere` int(11) NOT NULL,
  `libMinistere` varchar(300) NOT NULL,
  `adresseMinistere` varchar(300) NOT NULL,
  `idGov` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `ministere`
--

INSERT INTO `ministere` (`idMinistere`, `libMinistere`, `adresseMinistere`, `idGov`) VALUES
(1, 'رئاسة الحكـــومة', 'ساحة الحكومة – القصبة 1020 تونس\r\n\r\n\r\n\r\n', 5),
(2, 'وزارة العدل', '31 شارع باب بنات، القصبة 1006 تونس', 5),
(3, 'وزارة الدفاع الوطني', 'باب منارة - القصبة\r\n1008 تونس', 5),
(4, 'وزارة الداخلية', 'شارع الحبيب بورقيبة\r\n1000 تونس', 5),
(5, 'وزارة الشؤون الخارجية والهجرة والتونسيين بالخارج', 'شارع جامعة الدول العربية شمال الهلتون\r\n1030 تونس', 5),
(6, 'وزارة المالية', 'ساحة الحكومة بالقصبة\r\nتونس', 5),
(7, 'وزارة الصحة', 'باب سعدون\r\n1006 تونس', 5),
(8, 'وزارة الاقتصاد والتخطيط', 'شارع الشيخ محمد الفاضل بن عاشور مبنى «B4»، البرج \"أ\" المركز العمراني الشمالي\r\n1082 تونس', 5),
(9, 'وزارة الصناعة والمناجم والطاقة', 'عمارة \"بية\" عدد 40، نهج 8011 – مونبليزير\r\n1002 تونس', 5),
(10, 'وزارة الشؤون الاجتماعية', 'شارع باب بنات عدد 27\r\n1019 تونس', 5),
(11, 'وزارة التجارة وتنمية الصادرات', 'زاوية نهج غانا ونهج بيار دي كوبرتن ونهج الهادي نويرة\r\n1001 تونس', 5),
(12, 'وزارة الفلاحة والموارد المائية والصيد البحري', '30 نهج آلان سفاري\r\n1002 تونس', 5),
(13, 'وزارة التربية', 'شارع باب البنات\r\n1030 تونس', 5),
(14, 'وزارة التعليم العالي والبحث العلمي', 'شارع أولاد حفوز\r\n1030 تونس', 5),
(15, 'وزارة الشباب والرياضة', 'شارع محمد علي عقيد\r\n1003 تونس', 5),
(16, 'وزارة تكنولوجيات الإتصال', '88 شارع محمد الخامس\r\n1002 تونس', 5),
(17, 'وزارة النقل', '13 نهج البرجين، منبليزير\r\n1073 تونس', 5),
(18, 'وزارة التجهيز والإسكان', 'شارع الحبيب شريطة -حي الحدائق- البلفدير\r\n1002 تونس', 5),
(19, 'وزارة أملاك الدّولة والشؤون العقارية', 'شارع محمد الخامس قبالة البنك المركزي\r\n1000 تونس', 5),
(20, 'وزارة ﺍﻟﺒﻴﺌﺔ', 'شارع الأرض، المركز العمراني الشمالي\r\n1080 تونس', 5),
(21, 'وزارة السياحة', '1 شارع محمد الخامس\r\n1001 تونس', 5),
(22, 'وزارة الشؤون الدينية', '76 مكرّر، شارع باب البنات، القصبة\r\n1019 تونس', 5),
(23, 'وزارة الأسرة والمرأة والطفولة وكبار السن', '02 نهج عاصمة الجزائر\r\n1001 تونس', 5),
(24, 'وزارة الشؤون الثقافية', 'نهج 2 مارس 1934 القصبة،\r\n1002 تونس', 5),
(25, 'وزارة التشغيل والتكوين المهني', '10 شارع أولاد حفوز\r\n1002 تونس', 5);

-- --------------------------------------------------------

--
-- Structure de la table `projet`
--

CREATE TABLE `projet` (
  `idPro` int(11) NOT NULL,
  `idMinistere` int(11) NOT NULL,
  `idEtab` int(11) NOT NULL,
  `sujet` varchar(400) NOT NULL,
  `dateArrive` date NOT NULL,
  `procedurePro` varchar(30) NOT NULL,
  `cout` float NOT NULL,
  `proposition` varchar(400) NOT NULL,
  `idUser` int(11) NOT NULL,
  `etat` int(11) NOT NULL,
  `dateCreation` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `projet`
--

INSERT INTO `projet` (`idPro`, `idMinistere`, `idEtab`, `sujet`, `dateArrive`, `procedurePro`, `cout`, `proposition`, `idUser`, `etat`, `dateCreation`) VALUES
(6, 17, 15, 'مشروع اقتناء 30 عربة مترو لفائدة شركة النقل بتونس ضمن المشاريع العمومية الكبري ذات الطابع الإستراتيجي.', '2025-02-14', 'جديد', 450, 'ادراج مشروع اقتناء 30 عربة مترو ضمن المشاريع العمومية الكبري ذات الطابع الإستراتيجي.', 2, 0, '2025-11-24 17:54:17'),
(7, 11, 33, 'مشروع منصة أسواق الإنتاج الوسط.', '2025-01-02', 'جديد', 116, 'ادراج مشروع منصة أسواق الإنتاج الوسط صلب المشاريع الكبري كمشروع وطني استراتيجي ذو اولوية.', 2, 0, '2025-11-24 18:37:46');

-- --------------------------------------------------------

--
-- Structure de la table `projetcommission`
--

CREATE TABLE `projetcommission` (
  `idPc` int(11) NOT NULL,
  `idPro` int(11) NOT NULL,
  `idCom` int(11) NOT NULL,
  `naturePc` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `secteur`
--

CREATE TABLE `secteur` (
  `idSecteur` int(11) NOT NULL,
  `numSecteur` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `secteur`
--

INSERT INTO `secteur` (`idSecteur`, `numSecteur`) VALUES
(1, 1),
(2, 2),
(3, 3),
(4, 4),
(5, 5);

-- --------------------------------------------------------

--
-- Structure de la table `suivi`
--

CREATE TABLE `suivi` (
  `idSuivi` int(11) NOT NULL,
  `idPro` int(11) NOT NULL,
  `sujet` varchar(300) NOT NULL,
  `dateCreation` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Structure de la table `user`
--

CREATE TABLE `user` (
  `idUser` int(11) NOT NULL,
  `nomUser` varchar(200) NOT NULL,
  `emailUser` varchar(300) NOT NULL,
  `typeCpt` int(1) NOT NULL,
  `login` varchar(20) NOT NULL,
  `pw` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Déchargement des données de la table `user`
--

INSERT INTO `user` (`idUser`, `nomUser`, `emailUser`, `typeCpt`, `login`, `pw`) VALUES
(1, 'نضال الطرهوني', 'nidtar123@gmail.com', 1, 't.nidhal', '$2y$12$w47adqQpQIPgDZyZ41Dq4.bTBvXFi151ZXyZx9iPa7H9w1qoO2NDq'),
(2, 'إنصاف زمزم', 'insaf.zemzem2017@gmail.com', 2, 'admin', '$2y$12$HARA4nVt2ZHqv4SYTf1G0.bz1e5psG8yqSPH3mKeoQfacV2G/LUWO'),
(3, 'سفيان الخياري', 'sofian.khiari@pm.gov.tn', 2, 's.khiari', '$2y$12$LQv3c1yqjLVMwdfbqQXH/ubhBJ8K9bZQq8WJZ7.5qNLHh5sJxWpDC$2y$12$PkyoPQSAQ/ssivQVcABShOTr1oFPrpbLGR.gxKNZO4WLjSOdaik.S'),
(4, 'منى بن حسن', 'mouna.benhassen@pm.gov.tn', 2, 'm.benhassen', '$2y$12$dOJD2UpzQpvqgkcLsVJzTu7cIojj.7QB8zWSXgCnwUtM0jBGQ/2lq');

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `appeloffre`
--
ALTER TABLE `appeloffre`
  ADD PRIMARY KEY (`idApp`),
  ADD KEY `idPro` (`idPro`);

--
-- Index pour la table `commission`
--
ALTER TABLE `commission`
  ADD PRIMARY KEY (`idCom`);

--
-- Index pour la table `document`
--
ALTER TABLE `document`
  ADD PRIMARY KEY (`idDoc`),
  ADD KEY `idx_document_idPro` (`idPro`);

--
-- Index pour la table `etablissement`
--
ALTER TABLE `etablissement`
  ADD PRIMARY KEY (`idEtablissement`),
  ADD KEY `idMinistere` (`idMinistere`),
  ADD KEY `idGouvernement` (`idGouvernement`);

--
-- Index pour la table `fournisseur`
--
ALTER TABLE `fournisseur`
  ADD PRIMARY KEY (`idFour`);

--
-- Index pour la table `gouvernorat`
--
ALTER TABLE `gouvernorat`
  ADD PRIMARY KEY (`idGov`),
  ADD KEY `idSecteur` (`idSecteur`);

--
-- Index pour la table `journal`
--
ALTER TABLE `journal`
  ADD PRIMARY KEY (`idJournal`);

--
-- Index pour la table `lot`
--
ALTER TABLE `lot`
  ADD PRIMARY KEY (`lidLot`),
  ADD KEY `idFournisseur` (`idFournisseur`),
  ADD KEY `idAppelOffre` (`idAppelOffre`);

--
-- Index pour la table `ministere`
--
ALTER TABLE `ministere`
  ADD PRIMARY KEY (`idMinistere`),
  ADD KEY `idGov` (`idGov`);

--
-- Index pour la table `projet`
--
ALTER TABLE `projet`
  ADD PRIMARY KEY (`idPro`),
  ADD KEY `idEtab` (`idEtab`),
  ADD KEY `idMinistere` (`idMinistere`),
  ADD KEY `idUser` (`idUser`);

--
-- Index pour la table `projetcommission`
--
ALTER TABLE `projetcommission`
  ADD PRIMARY KEY (`idPc`),
  ADD KEY `idCom` (`idCom`),
  ADD KEY `idPro` (`idPro`);

--
-- Index pour la table `secteur`
--
ALTER TABLE `secteur`
  ADD PRIMARY KEY (`idSecteur`);

--
-- Index pour la table `suivi`
--
ALTER TABLE `suivi`
  ADD PRIMARY KEY (`idSuivi`);

--
-- Index pour la table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`idUser`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `appeloffre`
--
ALTER TABLE `appeloffre`
  MODIFY `idApp` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `commission`
--
ALTER TABLE `commission`
  MODIFY `idCom` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `document`
--
ALTER TABLE `document`
  MODIFY `idDoc` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT pour la table `etablissement`
--
ALTER TABLE `etablissement`
  MODIFY `idEtablissement` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=58;

--
-- AUTO_INCREMENT pour la table `fournisseur`
--
ALTER TABLE `fournisseur`
  MODIFY `idFour` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `gouvernorat`
--
ALTER TABLE `gouvernorat`
  MODIFY `idGov` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT pour la table `journal`
--
ALTER TABLE `journal`
  MODIFY `idJournal` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT pour la table `lot`
--
ALTER TABLE `lot`
  MODIFY `lidLot` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `ministere`
--
ALTER TABLE `ministere`
  MODIFY `idMinistere` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT pour la table `projet`
--
ALTER TABLE `projet`
  MODIFY `idPro` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT pour la table `projetcommission`
--
ALTER TABLE `projetcommission`
  MODIFY `idPc` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `secteur`
--
ALTER TABLE `secteur`
  MODIFY `idSecteur` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT pour la table `suivi`
--
ALTER TABLE `suivi`
  MODIFY `idSuivi` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `user`
--
ALTER TABLE `user`
  MODIFY `idUser` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `appeloffre`
--
ALTER TABLE `appeloffre`
  ADD CONSTRAINT `appeloffre_ibfk_1` FOREIGN KEY (`idPro`) REFERENCES `projet` (`idPro`);

--
-- Contraintes pour la table `document`
--
ALTER TABLE `document`
  ADD CONSTRAINT `document_ibfk_1` FOREIGN KEY (`idPro`) REFERENCES `projet` (`idPro`) ON DELETE CASCADE;

--
-- Contraintes pour la table `etablissement`
--
ALTER TABLE `etablissement`
  ADD CONSTRAINT `etablissement_ibfk_1` FOREIGN KEY (`idMinistere`) REFERENCES `ministere` (`idMinistere`),
  ADD CONSTRAINT `etablissement_ibfk_2` FOREIGN KEY (`idGouvernement`) REFERENCES `gouvernorat` (`idGov`);

--
-- Contraintes pour la table `gouvernorat`
--
ALTER TABLE `gouvernorat`
  ADD CONSTRAINT `gouvernorat_ibfk_1` FOREIGN KEY (`idSecteur`) REFERENCES `secteur` (`idSecteur`);

--
-- Contraintes pour la table `lot`
--
ALTER TABLE `lot`
  ADD CONSTRAINT `lot_ibfk_1` FOREIGN KEY (`idFournisseur`) REFERENCES `fournisseur` (`idFour`),
  ADD CONSTRAINT `lot_ibfk_2` FOREIGN KEY (`idAppelOffre`) REFERENCES `appeloffre` (`idApp`);

--
-- Contraintes pour la table `ministere`
--
ALTER TABLE `ministere`
  ADD CONSTRAINT `ministere_ibfk_1` FOREIGN KEY (`idGov`) REFERENCES `gouvernorat` (`idGov`);

--
-- Contraintes pour la table `projet`
--
ALTER TABLE `projet`
  ADD CONSTRAINT `projet_ibfk_1` FOREIGN KEY (`idEtab`) REFERENCES `etablissement` (`idEtablissement`),
  ADD CONSTRAINT `projet_ibfk_2` FOREIGN KEY (`idMinistere`) REFERENCES `ministere` (`idMinistere`),
  ADD CONSTRAINT `projet_ibfk_3` FOREIGN KEY (`idUser`) REFERENCES `user` (`idUser`);

--
-- Contraintes pour la table `projetcommission`
--
ALTER TABLE `projetcommission`
  ADD CONSTRAINT `projetcommission_ibfk_1` FOREIGN KEY (`idCom`) REFERENCES `commission` (`idCom`),
  ADD CONSTRAINT `projetcommission_ibfk_2` FOREIGN KEY (`idPro`) REFERENCES `projet` (`idPro`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
