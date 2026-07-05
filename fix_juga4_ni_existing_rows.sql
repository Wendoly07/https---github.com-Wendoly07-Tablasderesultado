UPDATE numeros_ganadores_sorteos_prod
SET
    result_raw = CONCAT(SUBSTRING(result_raw, 1, 1), '-', SUBSTRING(result_raw, 2, 1), '-', SUBSTRING(result_raw, 3, 1), '-', SUBSTRING(result_raw, 4, 1)),
    par1 = SUBSTRING(result_raw, 1, 1),
    par2 = SUBSTRING(result_raw, 2, 1),
    par3 = SUBSTRING(result_raw, 3, 1),
    par4 = SUBSTRING(result_raw, 4, 1),
    par5 = NULL,
    par6 = NULL,
    par7 = NULL
WHERE pais = 'Nicaragua'
  AND game_name LIKE 'Jug% 4'
  AND source_section = 'gamesdata.loto.com.ni'
  AND result_raw LIKE '[0-9][0-9][0-9][0-9]'
  AND par1 = result_raw;
