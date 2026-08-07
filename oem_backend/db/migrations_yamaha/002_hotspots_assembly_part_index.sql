CREATE INDEX IF NOT EXISTS ix_oem_hotspots_assembly_part
  ON oem_diagram_hotspots(assembly_part_id)
  WHERE assembly_part_id IS NOT NULL;
