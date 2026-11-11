@echo off
cd E:\azmoon

echo ========== AUTH CONFIG ==========
type config\auth.php

echo ========== MODELS LIST ==========
dir app\Models /b

echo ========== MIGRATIONS LIST ==========
dir database\migrations /b

echo ========== MODEL: User ==========
type app\Models\User.php

echo ========== MODEL: Exam ==========
type app\Models\Exam.php

echo ========== MODEL: Lab ==========
type app\Models\Lab.php

echo ========== MODEL: Computer ==========
type app\Models\Computer.php

echo ========== MODEL: Shift ==========
type app\Models\Shift.php

echo ========== MODEL: Subject ==========
type app\Models\Subject.php

echo ========== MODEL: Candidate ==========
type app\Models\Candidate.php

echo ========== MODEL: CandidateAssignment ==========
type app\Models\CandidateAssignment.php

echo ========== MODEL: EntryCard ==========
type app\Models\EntryCard.php

echo ========== MIGRATIONS: candidates ==========
for %%f in (database\migrations\*candidates*) do type "%%f"

echo ========== MIGRATIONS: candidate_assignments ==========
for %%f in (database\migrations\*candidate_assignments*) do type "%%f"

echo ========== MIGRATIONS: entry_cards ==========
for %%f in (database\migrations\*entry_cards*) do type "%%f"

echo ========== MIGRATIONS: shifts ==========
for %%f in (database\migrations\*shifts*) do type "%%f"

echo ========== MIGRATIONS: exams ==========
for %%f in (database\migrations\*exams*) do type "%%f"

echo ========== MIGRATIONS: labs ==========
for %%f in (database\migrations\*labs*) do type "%%f"

echo ========== MIGRATIONS: computers ==========
for %%f in (database\migrations\*computers*) do type "%%f"

echo ========== MIGRATIONS: users ==========
for %%f in (database\migrations\*users*) do type "%%f"
