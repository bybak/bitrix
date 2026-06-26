-- Speed up FK checks when updating/deleting oem_source_nodes (backfill-keys dedup).
CREATE INDEX IF NOT EXISTS ix_oem_assemblies_source_node ON oem_assemblies(source_node_id);
CREATE INDEX IF NOT EXISTS ix_oem_diagrams_source_node ON oem_diagrams(source_node_id);
CREATE INDEX IF NOT EXISTS ix_oem_assembly_parts_source_node ON oem_assembly_parts(source_node_id);
CREATE INDEX IF NOT EXISTS ix_oem_raw_snapshots_source_node ON oem_raw_snapshots(source_node_id);
