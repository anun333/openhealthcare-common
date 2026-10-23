#IfNotTable sample_thing
CREATE TABLE `sample_thing` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB;
#EndIf

#IfNotRow2D list_options list_id lists option_id sample_list
INSERT INTO `list_options` (`list_id`, `option_id`, `title`) VALUES ('lists', 'sample_list', 'Sample list');
INSERT INTO `list_options` (`list_id`, `option_id`, `title`) VALUES ('sample_list', 'first', 'First');
#EndIf

#IfNotRow2D layout_options form_id DEM field_id sample_field
INSERT INTO `layout_options` (`form_id`, `field_id`, `group_id`, `title`, `seq`) VALUES ('DEM', 'sample_field', '1', 'Sample field', 1);
#EndIf

#IfRow2D layout_options form_id DEM field_id sample_field
UPDATE `layout_options` SET `seq` = 2 WHERE `form_id` = 'DEM' AND `field_id` = 'sample_field' AND `seq` = 1;
UPDATE `layout_options` SET `seq` = 99 WHERE `form_id` = 'DEM' AND `field_id` = 'core_field';
#EndIf
